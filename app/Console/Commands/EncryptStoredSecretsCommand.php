<?php

namespace App\Console\Commands;

use App\Casts\SafeEncrypted;
use App\Models\Setting;
use App\Models\WebsiteCompliance\TemplateRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time / idempotent encryption of secrets that were stored as plaintext.
 */
class EncryptStoredSecretsCommand extends Command
{
    protected $signature = 'security:encrypt-stored-secrets {--dry-run : Report only, do not write}';

    protected $description = 'Encrypt platform Stripe secrets and WC cPanel credentials at rest (APP_KEY)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        foreach ([Setting::KEY_STRIPE_SECRET, Setting::KEY_STRIPE_WEBHOOK_SECRET] as $key) {
            $raw = Setting::query()->where('key', $key)->value('value');
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            if ($this->alreadyEncrypted($raw)) {
                $this->line("Setting {$key}: already encrypted");
                continue;
            }
            $this->info("Setting {$key}: encrypting plaintext".($dry ? ' (dry-run)' : ''));
            if (! $dry) {
                Setting::setSecret($key, $raw);
            }
            $updated++;
        }

        $connection = (new TemplateRequest)->getConnectionName()
            ?: config('database.default');
        $table = (new TemplateRequest)->getTable();

        $rows = DB::connection($connection)
            ->table($table)
            ->where(function ($q) {
                $q->whereNotNull('cpanel_db_password')->orWhereNotNull('cpanel_api_key');
            })
            ->orderBy('id')
            ->get(['id', 'cpanel_db_password', 'cpanel_api_key']);

        foreach ($rows as $row) {
            $patch = [];
            foreach (['cpanel_db_password', 'cpanel_api_key'] as $col) {
                $raw = $row->{$col} ?? null;
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                if ($this->alreadyEncrypted($raw)) {
                    continue;
                }
                $patch[$col] = Crypt::encryptString($raw);
            }
            if ($patch === []) {
                continue;
            }
            $this->info("TemplateRequest #{$row->id}: encrypting cPanel secrets".($dry ? ' (dry-run)' : ''));
            if (! $dry) {
                DB::connection($connection)->table($table)->where('id', $row->id)->update($patch);
            }
            $updated++;
        }

        $this->info($dry
            ? "Dry-run complete. Would update {$updated} record(s)."
            : "Done. Updated {$updated} record(s).");

        return self::SUCCESS;
    }

    private function alreadyEncrypted(string $value): bool
    {
        if (! SafeEncrypted::looksLikeLaravelCiphertext($value)) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            // Looks encrypted but wrong key — do not re-encrypt (would destroy data).
            return true;
        }
    }
}
