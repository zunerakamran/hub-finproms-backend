<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace Unicode em/en dashes (and common UTF-8 mojibake) in hub JSON
 * content so Terms, Privacy, Cookies, page copy, and email templates
 * render as plain ASCII hyphens on every hub.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $columns = [
        'terms_and_conditions',
        'privacy_policy',
        'cookie_notice',
        'page_content',
        'email_templates',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $select = ['id'];
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('hubs', $column)) {
                $select[] = $column;
            }
        }

        if (count($select) === 1) {
            return;
        }

        $hubs = DB::table('hubs')->select($select)->orderBy('id')->get();

        foreach ($hubs as $hub) {
            $updates = [];

            foreach ($this->columns as $column) {
                if (! property_exists($hub, $column) || $hub->{$column} === null) {
                    continue;
                }

                $raw = $hub->{$column};
                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                $healed = $this->healString($raw);
                if ($healed !== $raw) {
                    $updates[$column] = $healed;
                }
            }

            if ($updates === []) {
                continue;
            }

            $updates['updated_at'] = now();
            DB::table('hubs')->where('id', $hub->id)->update($updates);
        }
    }

    public function down(): void
    {
        // Irreversible data heal - no-op.
    }

    private function healString(string $value): string
    {
        $replacements = [
            "\u{2014}" => '-', // em dash
            "\u{2013}" => '-', // en dash
            // Classic mojibake when UTF-8 em/en dashes were misread as Windows-1252
            "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D" => '-', // em dash as a-circumflex euro rdquo
            "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C" => '-', // en dash as a-circumflex euro ldquo
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $value);
    }
};
