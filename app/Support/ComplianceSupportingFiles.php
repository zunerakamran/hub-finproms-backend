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
     * Collect uploaded files from a named multipart field.
     * Default field is supporting_files (compliance evidence).
     * Pass "attachments" for primary submission files (e.g. General Compliance / Firm Documents).
     *
     * @return list<UploadedFile>
     */
    public static function fromRequest(\Illuminate\Http\Request $request, string $field = 'supporting_files'): array
    {
        $normalized = self::normalize($request->file($field, []) ?: []);
        self::assertWithinLimits($normalized, $field);

        return $normalized;
    }

    /**
     * Who typically uploaded a supporting file for this workflow source.
     * Used by UI version history (advisor / approver / manager labels).
     */
    public static function roleForSource(?string $source): ?string
    {
        return match ($source) {
            'submit', 'resubmit', 'confirm_feedback' => 'advisor',
            'review', 'approve', 'reject', 'approve_with_feedback', 'schedule' => 'approver',
            'change_status' => 'manager',
            default => null,
        };
    }

    /**
     * @return array{uploaded_by_user_id: int|null, uploaded_by_name: string|null, source: string|null}
     */
    public static function attributionPayload(
        ?\App\Models\User $uploader,
        ?string $source
    ): array {
        return [
            'uploaded_by_user_id' => $uploader?->id ? (int) $uploader->id : null,
            'uploaded_by_name' => $uploader?->name ?: null,
            'source' => $source,
        ];
    }
}
