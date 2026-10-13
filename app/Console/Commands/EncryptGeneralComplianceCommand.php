<?php

namespace App\Console\Commands;

use App\Casts\SafeEncrypted;
use App\Models\GeneralComplianceRequestVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Encrypt Generic Compliance sensitive text fields at rest (description / feedback).
 *
 * File-byte encryption was reverted — it broke downloads on shared hosting.
 * Attachment files remain on the public disk as before.
 */
class EncryptGeneralComplianceCommand extends Command
{
    protected $signature = 'security:encrypt-general-compliance {--dry-run : Report only, do not write}';

    protected $description = 'Encrypt Generic Compliance description/feedback text at rest (APP_KEY)';

    /** @var list<string> */
    private const TEXT_COLUMNS = ['description', 'feedback', 'future_feedback'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = $this->encryptTextFields($dry);

        $this->info($dry
            ? "Dry-run complete. Would update {$updated} version row(s)."
            : "Done. Updated {$updated} version row(s).");
        $this->comment('Note: GC attachment files are NOT encrypted (public-disk storage restored).');

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
