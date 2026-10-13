<?php

namespace App\Console\Commands;

use App\Casts\SafeEncrypted;
use App\Models\GeneralComplianceRequestAttachment;
use App\Models\GeneralComplianceRequestVersion;
use App\Support\GeneralComplianceEncryptedFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time / idempotent encryption of Generic Compliance sensitive data:
 * - Version text: description, feedback, future_feedback
 * - Attachment / supporting-file bytes on disk
 */
class EncryptGeneralComplianceCommand extends Command
{
    protected $signature = 'security:encrypt-general-compliance {--dry-run : Report only, do not write}';

    protected $description = 'Encrypt Generic Compliance text fields and attachment files at rest (APP_KEY)';

    /** @var list<string> */
    private const TEXT_COLUMNS = ['description', 'feedback', 'future_feedback'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $textUpdated = $this->encryptTextFields($dry);
        $filesUpdated = $this->encryptFiles($dry);

        $this->info($dry
            ? "Dry-run complete. Would update {$textUpdated} version row(s) and {$filesUpdated} file(s)."
            : "Done. Updated {$textUpdated} version row(s) and {$filesUpdated} file(s).");

        return self::SUCCESS;
    }

    private function encryptTextFields(bool $dry): int
    {
        $updated = 0;
        $model = new GeneralComplianceRequestVersion;
        $connection = $model->getConnectionName() ?: config('database.default');
        $table = $model->getTable();

        $rows = DB::connection($connection)
            ->table($table)
            ->orderBy('id')
            ->get(['id', ...self::TEXT_COLUMNS]);

        foreach ($rows as $row) {
            $patch = [];
            foreach (self::TEXT_COLUMNS as $col) {
                $raw = $row->{$col} ?? null;
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                if ($this->alreadyEncryptedText($raw)) {
                    continue;
                }
                $patch[$col] = Crypt::encryptString($raw);
            }
            if ($patch === []) {
                continue;
            }
            $this->info("GC version #{$row->id}: encrypting text ".implode(', ', array_keys($patch))
                .($dry ? ' (dry-run)' : ''));
            if (! $dry) {
                DB::connection($connection)->table($table)->where('id', $row->id)->update($patch);
            }
            $updated++;
        }

        return $updated;
    }

    private function encryptFiles(bool $dry): int
    {
        $updated = 0;
        $model = new GeneralComplianceRequestAttachment;
        $connection = $model->getConnectionName() ?: config('database.default');
        $table = $model->getTable();

        $rows = DB::connection($connection)
            ->table($table)
            ->orderBy('id')
            ->get(['id', 'file_path', 'file_url', 'is_encrypted']);

        foreach ($rows as $row) {
            if ((bool) ($row->is_encrypted ?? false)) {
                continue;
            }
            $path = (string) ($row->file_path ?? '');
            if ($path === '') {
                continue;
            }

            $this->info("GC attachment #{$row->id}: encrypting file {$path}"
                .($dry ? ' (dry-run)' : ''));

            if ($dry) {
                $updated++;
                continue;
            }

            try {
                $result = GeneralComplianceEncryptedFiles::encryptExistingPublicFile($path);
            } catch (Throwable $e) {
                $this->error("GC attachment #{$row->id}: ".$e->getMessage());
                continue;
            }

            if ($result === null) {
                // Missing on disk, remote URL, or already encrypted under enc/ path.
                if (str_starts_with($path, GeneralComplianceEncryptedFiles::DIR.'/')) {
                    DB::connection($connection)->table($table)->where('id', $row->id)->update([
                        'is_encrypted' => true,
                        'file_url' => null,
                    ]);
                    $updated++;
                } else {
                    $this->warn("GC attachment #{$row->id}: skipped (missing or external URL)");
                }
                continue;
            }

            DB::connection($connection)->table($table)->where('id', $row->id)->update([
                'file_path' => $result['file_path'],
                'file_url' => $result['file_url'],
                'is_encrypted' => true,
            ]);
            $updated++;
        }

        return $updated;
    }

    private function alreadyEncryptedText(string $value): bool
    {
        if (! SafeEncrypted::looksLikeLaravelCiphertext($value)) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return true;
        }
    }
}
