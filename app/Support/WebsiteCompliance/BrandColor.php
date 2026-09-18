<?php

namespace App\Support\WebsiteCompliance;

/**
 * Normalize brand colours to #RRGGBB for template CSS variables.
 * Advisors sometimes type CSS named colours (pink, black) into the text field.
 */
class BrandColor
{
    /** @var array<string, string> */
    private const NAMED = [
        'black' => '#000000',
        'silver' => '#C0C0C0',
        'gray' => '#808080',
        'grey' => '#808080',
        'white' => '#FFFFFF',
        'maroon' => '#800000',
        'red' => '#FF0000',
        'purple' => '#800080',
        'fuchsia' => '#FF00FF',
        'magenta' => '#FF00FF',
        'green' => '#008000',
        'lime' => '#00FF00',
        'olive' => '#808000',
        'yellow' => '#FFFF00',
        'navy' => '#000080',
        'blue' => '#0000FF',
        'teal' => '#008080',
        'aqua' => '#00FFFF',
        'cyan' => '#00FFFF',
        'orange' => '#FFA500',
        'pink' => '#FFC0CB',
        'hotpink' => '#FF69B4',
        'deeppink' => '#FF1493',
        'lightpink' => '#FFB6C1',
        'coral' => '#FF7F50',
        'tomato' => '#FF6347',
        'salmon' => '#FA8072',
        'gold' => '#FFD700',
        'khaki' => '#F0E68C',
        'indigo' => '#4B0082',
        'violet' => '#EE82EE',
        'brown' => '#A52A2A',
        'chocolate' => '#D2691E',
        'crimson' => '#DC143C',
        'darkblue' => '#00008B',
        'darkgreen' => '#006400',
        'darkred' => '#8B0000',
        'darkslategray' => '#2F4F4F',
        'darkslategrey' => '#2F4F4F',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'lightblue' => '#ADD8E6',
        'lightgray' => '#D3D3D3',
        'lightgrey' => '#D3D3D3',
        'skyblue' => '#87CEEB',
        'steelblue' => '#4682B4',
        'turquoise' => '#40E0D0',
    ];

    public static function toHex(?string $value, string $fallback = '#0B1B3D'): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return strtoupper($fallback);
        }

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $raw, $m)) {
            return '#'.strtoupper($m[1]);
        }

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $raw, $m)) {
            $h = $m[1];

            return '#'.strtoupper($h[0].$h[0].$h[1].$h[1].$h[2].$h[2]);
        }

        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $raw, $m)) {
            $toHex = static fn (string $n): string => str_pad(
                dechex(max(0, min(255, (int) $n))),
                2,
                '0',
                STR_PAD_LEFT
            );

            return '#'.strtoupper($toHex($m[1]).$toHex($m[2]).$toHex($m[3]));
        }

        $key = strtolower(preg_replace('/\s+/', '', $raw) ?? $raw);
        if (isset(self::NAMED[$key])) {
            return self::NAMED[$key];
        }

        return strtoupper($fallback);
    }
}
