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
 * Encrypt Generic Compliance sensitive text + attachment bytes at rest (APP_KEY).
 *
 * Text: description / feedback / future_feedback on version rows.
 * Files: migrate public-disk attachments onto gc_encrypted disk (GCENC1 ciphertext).
 * Downloads remain via authenticated GET /api/gc/file/{id}.
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
        $filesUpdated = $this->encryptAttachmentFiles($dry);

        $this->info($dry
            ? "Dry-run complete. Would update {$textUpdated} version row(s) and {$filesUpdated} attachment(s)."
            : "Done. Updated {$textUpdated} version row(s) and {$filesUpdated} attachment(s).");

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

    private function encryptAttachmentFiles(bool $dry): int
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
            $path = (string) ($row->file_path ?? '');
            if ($path === '') {
                continue;
            }

            $already = ! empty($row->is_encrypted)
                || str_starts_with($path, 'enc/')
                || str_starts_with($path, 'general-compliance/enc/');

            if ($already) {
                // Ensure flag + clear public URL even if path was already migrated.
                if (! empty($row->is_encrypted) && empty($row->file_url)) {
                    continue;
                }
                $this->line("GC attachment #{$row->id}: already encrypted"
                    .(empty($row->is_encrypted) || filled($row->file_url) ? ' (fixing metadata)' : ''));
                if (! $dry) {
                    DB::connection($connection)->table($table)->where('id', $row->id)->update([
                        'is_encrypted' => true,
                        'file_url' => null,
                    ]);
                }
                $updated++;

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
                $this->warn("GC attachment #{$row->id}: source file missing, skipped.");

                continue;
            }

            DB::connection($connection)->table($table)->where('id', $row->id)->update([
                'file_path' => $result['file_path'],
                'file_url' => null,
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
