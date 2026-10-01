<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules / helpers for optional compliance supporting files
 * (Social Media, General, and Website Compliance).
 */
final class ComplianceSupportingFiles
{
    public const MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,gif,webp,zip';

    public const MAX_FILES = 10;

    public const MAX_KB = 10240;

    /**
     * Laravel validation rules for an optional supporting_files[] upload field.
     *
     * @return array<string, list<string>>
     */
    public static function optionalUploadRules(string $field = 'supporting_files'): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_FILES],
            $field.'.*' => [
                'file',
                'mimes:'.self::MIMES,
                'max:'.self::MAX_KB,
            ],
        ];
    }

    /**
     * @param  mixed  $files
     * @return list<UploadedFile>
     */
    public static function normalize(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public static function assertWithinLimits(array $files, string $field = 'supporting_files'): void
    {
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                $field => 'You may upload at most '.self::MAX_FILES.' supporting files.',
            ]);
        }
    }

    /**
     * Collect supporting files from a request, accepting either
     * supporting_files or attachments as the multipart field name.
     *
     * @return list<UploadedFile>
     */
    public static function fromRequest(\Illuminate\Http\Request $request): array
    {
        $files = $request->file('supporting_files', null);
        if ($files === null || $files === []) {
            $files = $request->file('attachments', []);
        }

        $normalized = self::normalize($files ?: []);
        self::assertWithinLimits($normalized);

        return $normalized;
    }
}
