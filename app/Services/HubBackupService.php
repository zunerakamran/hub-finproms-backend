<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubBackup;
use App\Models\User;
use App\Support\ZipSupport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HubBackupService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly ZipSupport $zip,
    ) {}

    /**
     * @return array{
     *   enabled: bool,
     *   time: string,
     *   timezone: string,
     *   frequency: string,
     *   weekday: int|null,
     *   retention_local: int,
     *   retention_central: int,
     *   last_run_at: string|null,
     *   token_set: bool,
     *   due_now: bool,
     *   single_store: bool
     * }
     */
    public function schedulePayload(Hub $hub): array
    {
        return [
            'enabled' => (bool) ($hub->backup_enabled ?? false),
            'time' => (string) ($hub->backup_time ?: '02:00'),
            'timezone' => (string) ($hub->backup_timezone ?: 'UTC'),
            'frequency' => (string) ($hub->backup_frequency ?: 'daily'),
            'weekday' => $hub->backup_weekday !== null ? (int) $hub->backup_weekday : null,
            'retention_local' => (int) ($hub->backup_retention_local ?: 3),
            'retention_central' => (int) ($hub->backup_retention_central ?: 14),
            'last_run_at' => $hub->backup_last_run_at?->toIso8601String(),
            'token_set' => filled($hub->backup_token),
            'due_now' => $this->isDue($hub),
            // Central backs itself up once on this server (no local+central duplicate).
            'single_store' => $hub->isControlPlane(),
        ];
    }

    public function ensureBackupToken(Hub $hub): string
    {
        if (filled($hub->backup_token)) {
            return (string) $hub->backup_token;
        }

        $token = Str::random(64);
        $hub->forceFill(['backup_token' => $token])->save();

        return $token;
    }

    public function isDue(Hub $hub, ?Carbon $now = null): bool
    {
        if (! ($hub->backup_enabled ?? false)) {
            return false;
        }

        $timezone = (string) ($hub->backup_timezone ?: 'UTC');
        try {
            $localNow = ($now?->copy() ?? now())->timezone($timezone);
        } catch (Throwable) {
            $localNow = ($now?->copy() ?? now())->timezone('UTC');
        }

        $time = (string) ($hub->backup_time ?: '02:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        if ($localNow->hour !== $hour || $localNow->minute !== $minute) {
            return false;
        }

        $frequency = (string) ($hub->backup_frequency ?: 'daily');
        if ($frequency === 'weekly') {
            $weekday = $hub->backup_weekday !== null ? (int) $hub->backup_weekday : 0;
            if ((int) $localNow->dayOfWeek !== $weekday) {
                return false;
            }
        }

        if ($hub->backup_last_run_at) {
            try {
                $lastLocal = $hub->backup_last_run_at->copy()->timezone($timezone);
            } catch (Throwable) {
                $lastLocal = $hub->backup_last_run_at->copy();
            }
            if ($lastLocal->format('Y-m-d H:i') === $localNow->format('Y-m-d H:i')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a backup archive for THIS deploy (DB + storage files).
     *
     * Content hubs store under backups/{slug}/ (local). Central Hub Controller
     * stores a single copy under backups/central/{slug}/ (same server — no duplicate).
     */
    public function createLocalBackup(
        ?Hub $hub = null,
        string $triggeredBy = HubBackup::TRIGGER_SCHEDULE,
        ?User $user = null
    ): HubBackup {
        $hub ??= $this->hubs->current();
        $slug = (string) $hub->slug;
        $singleStore = (bool) config('hub.is_control_plane');
        $location = $singleStore ? HubBackup::LOCATION_CENTRAL : HubBackup::LOCATION_LOCAL;
        $workDir = storage_path('app/backups/tmp/'.Str::lower(Str::random(12)));
        $backupsDir = $singleStore
            ? storage_path('app/backups/central/'.$slug)
            : storage_path('app/backups/'.$slug);
        $this->ensureDirectory($workDir);
        $this->ensureDirectory($backupsDir);

        $record = HubBackup::query()->create([
            'hub_id' => $hub->id,
            'hub_slug' => $slug,
            'location' => $location,
            'triggered_by' => $triggeredBy,
            'status' => HubBackup::STATUS_RUNNING,
            'includes_database' => true,
            'includes_files' => true,
            'created_by_user_id' => $user?->id,
        ]);

        try {
            $sqlPath = $workDir.DIRECTORY_SEPARATOR.'database.sql';
            $this->dumpLocalDatabase($sqlPath);

            $filesDir = $workDir.DIRECTORY_SEPARATOR.'files';
            $this->ensureDirectory($filesDir);
            $this->copyStorageTrees($filesDir);

            $stamp = now()->format('Ymd_His');
            $filename = $slug.'_'.$stamp.'.zip';
            $zipAbsolute = $backupsDir.DIRECTORY_SEPARATOR.$filename;
            $this->zipDirectory($workDir, $zipAbsolute);

            $relative = $singleStore
                ? 'backups/central/'.$slug.'/'.$filename
                : 'backups/'.$slug.'/'.$filename;
            $size = filesize($zipAbsolute) ?: 0;
            $checksum = hash_file('sha256', $zipAbsolute) ?: null;

            $record->update([
                'status' => HubBackup::STATUS_COMPLETED,
                'filename' => $filename,
                'disk_path' => $relative,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            if (Schema::hasColumn('hubs', 'backup_last_run_at')) {
                $hub->forceFill(['backup_last_run_at' => now()])->save();
            }

            if ($singleStore) {
                $this->pruneCentral($hub);
            } else {
                $this->pruneLocal($hub);
            }
            $this->removeDirectory($workDir);

            return $record->fresh();
        } catch (Throwable $e) {
            report($e);
            $record->update([
                'status' => HubBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->removeDirectory($workDir);
            throw $e;
        }
    }

    /**
     * Upload a completed local backup to Central (content hubs only).
     *
     * @param  string|null  $uploadToken  Prefer the key Central used to call /run (avoids slug/token drift).
     * @param  string|null  $hubSlug  Canonical Central registry slug for this hub.
     */
    public function uploadLocalBackupToCentral(
        HubBackup $backup,
        ?string $centralReceiveUrl = null,
        ?string $uploadToken = null,
        ?string $hubSlug = null,
    ): ?array {
        if (config('hub.is_control_plane')) {
            return null;
        }

        if ($backup->status !== HubBackup::STATUS_COMPLETED || ! $backup->disk_path) {
            throw new RuntimeException('Backup is not ready to upload.');
        }

        $centralUrl = rtrim((string) ($centralReceiveUrl ?: config('services.hub_backup.central_api_url', '')), '/');
        if ($centralUrl === '') {
            Log::info('hub backup: Central API URL not set; skipping upload', [
                'backup_id' => $backup->id,
            ]);

            return null;
        }

        // Accept either base /api or full .../internal/hub-backups/receive
        if (! str_contains($centralUrl, 'internal/hub-backups/receive')) {
            $centralUrl .= '/internal/hub-backups/receive';
        }

        $absolute = $backup->absolutePath();
        if (! $absolute || ! is_file($absolute)) {
            throw new RuntimeException('Backup archive missing on disk.');
        }

        $hub = $this->hubs->current();
        $token = (string) ($uploadToken
            ?: $hub->backup_token
            ?: config('services.hub_backup.secret', ''));
        if ($token === '') {
            throw new RuntimeException('Backup token is not configured for this hub.');
        }

        $slug = (string) ($hubSlug ?: $hub->slug);
        if ($slug === '') {
            throw new RuntimeException('Hub slug is missing; cannot upload backup to Central.');
        }

        $verify = (bool) config('services.http_tls_verify', true);
        $response = Http::withOptions(['verify' => $verify])
            ->timeout((int) config('services.hub_backup.upload_timeout', 600))
            ->withHeaders([
                'X-Hub-Backup-Key' => $token,
                'X-Hub-Slug' => $slug,
            ])
            ->attach('archive', file_get_contents($absolute), $backup->filename ?: 'backup.zip')
            ->post($centralUrl, [
                'hub_slug' => $slug,
                'checksum' => $backup->checksum,
                'triggered_by' => $backup->triggered_by,
                'includes_database' => $backup->includes_database ? '1' : '0',
                'includes_files' => $backup->includes_files ? '1' : '0',
                'size_bytes' => (string) ($backup->size_bytes ?? 0),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Central rejected backup upload: HTTP '.$response->status().' '.$response->body()
            );
        }

        return is_array($response->json()) ? $response->json() : ['message' => 'ok'];
    }

    /**
     * Store an archive received from a content hub (Central only).
     *
     * @param  array<string, mixed>  $meta
     */
    public function receiveOnCentral(string $hubSlug, string $uploadedAbsolutePath, array $meta = []): HubBackup
    {
        if (! config('hub.is_control_plane')) {
            throw new RuntimeException('Only Central Hub can receive remote backups.');
        }

        $hub = Hub::query()->where('slug', $hubSlug)->first();
        $dir = storage_path('app/backups/central/'.$hubSlug);
        $this->ensureDirectory($dir);

        $stamp = now()->format('Ymd_His');
        $filename = $hubSlug.'_'.$stamp.'.zip';
        $dest = $dir.DIRECTORY_SEPARATOR.$filename;
        if (! @rename($uploadedAbsolutePath, $dest) && ! @copy($uploadedAbsolutePath, $dest)) {
            throw new RuntimeException('Could not store received backup archive.');
        }
        @unlink($uploadedAbsolutePath);

        $relative = 'backups/central/'.$hubSlug.'/'.$filename;
        $size = filesize($dest) ?: (int) ($meta['size_bytes'] ?? 0);
        $checksum = hash_file('sha256', $dest) ?: ($meta['checksum'] ?? null);

        $record = HubBackup::query()->create([
            'hub_id' => $hub?->id,
            'hub_slug' => $hubSlug,
            'location' => HubBackup::LOCATION_CENTRAL,
            'triggered_by' => (string) ($meta['triggered_by'] ?? HubBackup::TRIGGER_RECEIVE),
            'status' => HubBackup::STATUS_COMPLETED,
            'filename' => $filename,
            'disk_path' => $relative,
            'size_bytes' => $size,
            'checksum' => $checksum,
            'includes_database' => filter_var($meta['includes_database'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'includes_files' => filter_var($meta['includes_files'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'completed_at' => now(),
        ]);

        if ($hub) {
            $this->pruneCentral($hub);
        }

        return $record;
    }

    /**
     * Run due backups for THIS deploy (local + upload to Central when configured).
     *
     * @return list<HubBackup>
     */
    public function runDueForCurrentDeploy(): array
    {
        $hub = $this->hubs->current();
        if (! $this->isDue($hub)) {
            return [];
        }

        $created = $this->createLocalBackup($hub, HubBackup::TRIGGER_SCHEDULE);
        $results = [$created];

        // Content hubs upload a second copy to Central. Central itself already
        // stores a single copy under backups/central/ — do not mirror again.
        if (! config('hub.is_control_plane')) {
            try {
                $this->uploadLocalBackupToCentral($created);
            } catch (Throwable $e) {
                report($e);
                Log::warning('hub backup: upload to Central failed', [
                    'hub' => $hub->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Central-only: trigger due backups on remote content hubs via their api_url.
     *
     * @return list<string>
     */
    public function triggerDueRemoteHubsFromCentral(): array
    {
        if (! config('hub.is_control_plane')) {
            return [];
        }

        $messages = [];
        $receiveUrl = rtrim((string) config('app.url'), '/').'/api/internal/hub-backups/receive';
        $verify = (bool) config('services.http_tls_verify', true);

        $hubs = Hub::query()
            ->where('backup_enabled', true)
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->get();

        foreach ($hubs as $hub) {
            if (! $this->isDue($hub)) {
                continue;
            }

            $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
            if ($apiUrl === '') {
                $messages[] = $hub->slug.': skipped (no api_url)';
                continue;
            }

            try {
                $token = $this->ensureBackupToken($hub);
                if ($hub->hasRemoteDatabaseConfigured()) {
                    try {
                        app(WhiteLabelHubSyncService::class)->pushSettings($hub);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                $response = Http::withOptions(['verify' => $verify])
                    ->timeout((int) config('services.hub_backup.upload_timeout', 600))
                    ->withHeaders([
                        'X-Hub-Backup-Key' => $token,
                        'X-Hub-Slug' => $hub->slug,
                        'X-Central-Receive-Url' => $receiveUrl,
                    ])
                    ->post($apiUrl.'/internal/hub-backups/run', [
                        'triggered_by' => HubBackup::TRIGGER_SCHEDULE,
                        'central_receive_url' => $receiveUrl,
                        'hub_slug' => $hub->slug,
                    ]);

                if ($response->successful()) {
                    $hub->forceFill(['backup_last_run_at' => now()])->save();
                    $messages[] = $hub->slug.': ok';
                } else {
                    $messages[] = $hub->slug.': HTTP '.$response->status();
                }
            } catch (Throwable $e) {
                report($e);
                $messages[] = $hub->slug.': '.$e->getMessage();
            }
        }

        return $messages;
    }

    public function mirrorLocalToCentralStore(HubBackup $local, Hub $hub): HubBackup
    {
        $src = $local->absolutePath();
        if (! $src || ! is_file($src)) {
            throw new RuntimeException('Local backup file missing.');
        }

        $dir = storage_path('app/backups/central/'.$hub->slug);
        $this->ensureDirectory($dir);
        $filename = $local->filename ?: basename($src);
        $dest = $dir.DIRECTORY_SEPARATOR.$filename;
        if (! @copy($src, $dest)) {
            throw new RuntimeException('Could not mirror backup to central store.');
        }

        $relative = 'backups/central/'.$hub->slug.'/'.$filename;
        $record = HubBackup::query()->create([
            'hub_id' => $hub->id,
            'hub_slug' => $hub->slug,
            'location' => HubBackup::LOCATION_CENTRAL,
            'triggered_by' => $local->triggered_by,
            'status' => HubBackup::STATUS_COMPLETED,
            'filename' => $filename,
            'disk_path' => $relative,
            'size_bytes' => filesize($dest) ?: $local->size_bytes,
            'checksum' => hash_file('sha256', $dest) ?: $local->checksum,
            'includes_database' => $local->includes_database,
            'includes_files' => $local->includes_files,
            'created_by_user_id' => $local->created_by_user_id,
            'completed_at' => now(),
        ]);

        $this->pruneCentral($hub);

        return $record;
    }

    /**
     * Restore THIS deploy from a zip archive on disk.
     */
    public function restoreLocalFromArchive(string $zipAbsolutePath): void
    {
        if (! is_file($zipAbsolutePath)) {
            throw new RuntimeException('Backup archive not found.');
        }

        $workDir = storage_path('app/backups/tmp/restore_'.Str::lower(Str::random(10)));
        $this->ensureDirectory($workDir);

        try {
            $this->zip->extract($zipAbsolutePath, $workDir);

            $sql = $workDir.DIRECTORY_SEPARATOR.'database.sql';
            if (is_file($sql)) {
                $this->importLocalDatabase($sql);
            }

            $files = $workDir.DIRECTORY_SEPARATOR.'files';
            if (is_dir($files)) {
                $this->restoreStorageTrees($files);
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Push a Central-stored backup to a content hub for restore.
     */
    public function restoreRemoteHubFromCentral(Hub $hub, HubBackup $backup): void
    {
        if (! config('hub.is_control_plane')) {
            throw new RuntimeException('Remote restore can only be initiated from Central.');
        }

        if ($hub->isControlPlane()) {
            $path = $backup->absolutePath();
            if (! $path) {
                throw new RuntimeException('Backup file missing.');
            }
            $this->restoreLocalFromArchive($path);

            return;
        }

        $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
        if ($apiUrl === '') {
            throw new RuntimeException('Hub api_url is not set; cannot push restore to that server.');
        }

        $token = (string) ($hub->backup_token ?: '');
        if ($token === '') {
            throw new RuntimeException('Hub backup token is missing. Save backup settings first.');
        }

        $absolute = $backup->absolutePath();
        if (! $absolute || ! is_file($absolute)) {
            throw new RuntimeException('Central backup archive missing on disk.');
        }

        $verify = (bool) config('services.http_tls_verify', true);
        $response = Http::withOptions(['verify' => $verify])
            ->timeout((int) config('services.hub_backup.upload_timeout', 600))
            ->withHeaders([
                'X-Hub-Backup-Key' => $token,
                'X-Hub-Slug' => $hub->slug,
            ])
            ->attach('archive', file_get_contents($absolute), $backup->filename ?: 'backup.zip')
            ->post($apiUrl.'/internal/hub-backups/restore');

        if (! $response->successful()) {
            throw new RuntimeException(
                'Hub restore failed: HTTP '.$response->status().' '.$response->body()
            );
        }
    }

    public function pruneLocal(Hub $hub): void
    {
        $keep = max(1, (int) ($hub->backup_retention_local ?: 3));
        $this->pruneByLocation($hub->slug, HubBackup::LOCATION_LOCAL, $keep, $hub->id);
    }

    public function pruneCentral(Hub $hub): void
    {
        $keep = max(1, (int) ($hub->backup_retention_central ?: 14));
        $this->pruneByLocation($hub->slug, HubBackup::LOCATION_CENTRAL, $keep, $hub->id);
    }

    private function pruneByLocation(string $slug, string $location, int $keep, ?int $hubId): void
    {
        $query = HubBackup::query()
            ->where('hub_slug', $slug)
            ->where('location', $location)
            ->where('status', HubBackup::STATUS_COMPLETED)
            ->orderByDesc('id');

        $idsToKeep = (clone $query)->limit($keep)->pluck('id')->all();
        $old = HubBackup::query()
            ->where('hub_slug', $slug)
            ->where('location', $location)
            ->where('status', HubBackup::STATUS_COMPLETED)
            ->when($idsToKeep !== [], fn ($q) => $q->whereNotIn('id', $idsToKeep))
            ->get();

        foreach ($old as $row) {
            $path = $row->absolutePath();
            if ($path && is_file($path)) {
                @unlink($path);
            }
            $row->delete();
        }
    }

    private function dumpLocalDatabase(string $sqlPath): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql' && $this->tryMysqldump($sqlPath)) {
            return;
        }

        if ($driver === 'sqlite') {
            $dbPath = config("database.connections.{$connection}.database");
            if (is_string($dbPath) && is_file($dbPath)) {
                file_put_contents($sqlPath, "-- sqlite binary copy marker\n");
                // Also copy the sqlite file beside SQL for restore.
                copy($dbPath, dirname($sqlPath).DIRECTORY_SEPARATOR.'database.sqlite');

                return;
            }
        }

        $this->phpDumpDatabase($sqlPath);
    }

    private function tryMysqldump(string $sqlPath): bool
    {
        $connection = config('database.default');
        $cfg = config("database.connections.{$connection}");
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '3306');
        $database = (string) ($cfg['database'] ?? '');
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');

        if ($database === '' || $username === '') {
            return false;
        }

        $mysqldump = $this->findBinary('mysqldump');
        if (! $mysqldump) {
            return false;
        }

        $cmd = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --routines --triggers --result-file=%s %s',
            escapeshellarg($mysqldump),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($sqlPath),
            escapeshellarg($database)
        );

        $env = [];
        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptor, $pipes, null, $env + $_ENV);
        if (! is_resource($process)) {
            return false;
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
            Log::info('mysqldump failed; falling back to PHP dump', ['stderr' => $stderr]);

            return false;
        }

        return true;
    }

    private function phpDumpDatabase(string $sqlPath): void
    {
        $handle = fopen($sqlPath, 'wb');
        if (! $handle) {
            throw new RuntimeException('Could not write database dump.');
        }

        fwrite($handle, "-- FinProms hub backup\n");
        fwrite($handle, '-- Generated: '.now()->toIso8601String()."\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = DB::select('SHOW TABLES');
        $key = null;
        foreach ($tables as $row) {
            $arr = (array) $row;
            $key ??= array_key_first($arr);
            $table = (string) $arr[$key];
            $create = DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $createSql = ((array) $create)['Create Table'] ?? null;
            if (! $createSql) {
                continue;
            }
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $createSql.";\n\n");

            DB::table($table)->orderByRaw('1')->chunk(200, function ($rows) use ($handle, $table) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $cols = array_map(fn ($c) => '`'.str_replace('`', '``', $c).'`', array_keys($data));
                    $vals = array_map(function ($v) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        if (is_bool($v)) {
                            return $v ? '1' : '0';
                        }

                        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v)."'";
                    }, array_values($data));
                    fwrite(
                        $handle,
                        'INSERT INTO `'.$table.'` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n"
                    );
                }
            });
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function importLocalDatabase(string $sqlPath): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        $sqliteCopy = dirname($sqlPath).DIRECTORY_SEPARATOR.'database.sqlite';
        if ($driver === 'sqlite' && is_file($sqliteCopy)) {
            $dbPath = config("database.connections.{$connection}.database");
            DB::disconnect($connection);
            if (! @copy($sqliteCopy, $dbPath)) {
                throw new RuntimeException('Could not restore sqlite database file.');
            }

            return;
        }

        if ($driver === 'mysql' && $this->tryMysqlImport($sqlPath)) {
            return;
        }

        $sql = file_get_contents($sqlPath);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Database dump is empty.');
        }

        // Split on ;\n while keeping it simple for our own dumps.
        $statements = preg_split('/;\s*\n/', $sql) ?: [];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            DB::unprepared($statement);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function tryMysqlImport(string $sqlPath): bool
    {
        $connection = config('database.default');
        $cfg = config("database.connections.{$connection}");
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return false;
        }

        $cmd = sprintf(
            '%s --host=%s --port=%s --user=%s %s',
            escapeshellarg($mysql),
            escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($cfg['port'] ?? '3306')),
            escapeshellarg((string) ($cfg['username'] ?? '')),
            escapeshellarg((string) ($cfg['database'] ?? ''))
        );

        $env = [];
        $password = (string) ($cfg['password'] ?? '');
        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $descriptor = [
            0 => ['file', $sqlPath, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptor, $pipes, null, $env + $_ENV);
        if (! is_resource($process)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            Log::warning('mysql import failed', ['stderr' => $stderr]);

            return false;
        }

        return true;
    }

    private function copyStorageTrees(string $destRoot): void
    {
        $map = [
            'public' => storage_path('app/public'),
            'uploads' => storage_path('app/uploads'),
        ];
        foreach ($map as $name => $src) {
            if (! is_dir($src)) {
                continue;
            }
            $target = $destRoot.DIRECTORY_SEPARATOR.$name;
            $this->copyDirectory($src, $target);
        }
    }

    private function restoreStorageTrees(string $filesRoot): void
    {
        $map = [
            'public' => storage_path('app/public'),
            'uploads' => storage_path('app/uploads'),
        ];
        foreach ($map as $name => $dest) {
            $src = $filesRoot.DIRECTORY_SEPARATOR.$name;
            if (! is_dir($src)) {
                continue;
            }
            $this->ensureDirectory($dest);
            $this->copyDirectory($src, $dest);
        }
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $this->zip->zipDirectory($sourceDir, $zipPath);
    }

    private function copyDirectory(string $src, string $dest): void
    {
        $this->ensureDirectory($dest);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                $this->ensureDirectory($target);
            } else {
                $this->ensureDirectory(dirname($target));
                copy($item->getRealPath(), $target);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create directory: '.$path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($path);
    }

    private function findBinary(string $name): ?string
    {
        foreach (['C:\\xampp\\mysql\\bin\\', '/usr/bin/', '/usr/local/bin/'] as $prefix) {
            $candidate = $prefix.$name.(str_contains($prefix, '\\') ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $cmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where '.$name : 'which '.$name;
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code === 0 && isset($out[0]) && is_file($out[0])) {
            return $out[0];
        }

        return null;
    }
}
