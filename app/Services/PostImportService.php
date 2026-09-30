<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\PostImportXlsxTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Bulk-create Central library posts from an Excel sheet (manual posts).
 */
class PostImportService
{
    public function __construct(
        private readonly PostImportXlsxTemplate $template,
        private readonly ContentTaxonomyService $taxonomy
    ) {}

    public function templateXlsx(): string
    {
        return $this->template->build(
            ContentType::query()->orderBy('name')->pluck('name')->all(),
            Category::query()->orderBy('name')->pluck('name')->all(),
            Tag::query()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * @return array{summary: array{created: int, skipped: int, errors: int}, rows: list<array<string, mixed>>}
     */
    public function import(UploadedFile $file, User $actor): array
    {
        $rows = $this->parseRows($file);
        if ($rows === []) {
            throw new InvalidArgumentException('The spreadsheet has no data rows.');
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $line = $index + 2; // header is row 1
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $skipped++;
                    $results[] = [
                        'row' => $line,
                        'status' => 'skipped',
                        'message' => 'Empty title.',
                    ];
                    continue;
                }

                try {
                    $type = $this->resolveType((string) ($row['type'] ?? ''));
                    $categories = $this->splitList((string) ($row['categories'] ?? ''));
                    $tags = $this->splitList((string) ($row['tags'] ?? ''));
                    $this->taxonomy->ensureNames($type, $categories, $tags);

                    $credits = (int) ($row['credits_cost'] ?? 10);
                    if ($credits < 0) {
                        $credits = 0;
                    }

                    $activeRaw = strtolower(trim((string) ($row['is_active'] ?? 'yes')));
                    $isActive = ! in_array($activeRaw, ['0', 'no', 'false', 'n', 'off'], true);

                    $post = Post::query()->create([
                        'created_by' => $actor->id,
                        'title' => mb_substr($title, 0, 255),
                        'description' => trim((string) ($row['description'] ?? '')) ?: null,
                        'type' => $type,
                        'categories' => $categories,
                        'tags' => $tags,
                        'credits_cost' => $credits,
                        'canva_link' => trim((string) ($row['canva_link'] ?? '')) ?: null,
                        'is_active' => $isActive,
                        'creation_source' => Post::SOURCE_MANUAL,
                    ]);

                    $created++;
                    $results[] = [
                        'row' => $line,
                        'status' => 'created',
                        'post_id' => $post->id,
                        'title' => $post->title,
                    ];
                } catch (Throwable $e) {
                    $errors++;
                    $results[] = [
                        'row' => $line,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'summary' => [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
            'rows' => $results,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseRows(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new InvalidArgumentException('Could not read the uploaded file.');
        }

        $libraryPath = base_path('vendor/shuchkin/simplexlsx/src/SimpleXLSX.php');
        if (! class_exists(\Shuchkin\SimpleXLSX::class) && is_file($libraryPath)) {
            require_once $libraryPath;
        }
        if (! class_exists(\Shuchkin\SimpleXLSX::class)) {
            throw new RuntimeException('Excel parser is not installed.');
        }

        $xlsx = \Shuchkin\SimpleXLSX::parse($path);
        if ($xlsx === false) {
            $error = method_exists(\Shuchkin\SimpleXLSX::class, 'parseError')
                ? \Shuchkin\SimpleXLSX::parseError()
                : 'Invalid spreadsheet.';
            throw new InvalidArgumentException((string) $error);
        }

        $sheet = $xlsx->rows(0);
        if (! is_array($sheet) || $sheet === []) {
            return [];
        }

        $header = array_map(
            fn ($cell) => strtolower(trim((string) $cell)),
            $sheet[0] ?? []
        );

        $map = [];
        foreach (['title', 'description', 'type', 'categories', 'tags', 'credits_cost', 'canva_link', 'is_active'] as $key) {
            $idx = array_search($key, $header, true);
            if ($idx !== false) {
                $map[$key] = (int) $idx;
            }
        }

        if (! isset($map['title'])) {
            throw new InvalidArgumentException('Spreadsheet must include a "title" column.');
        }

        $out = [];
        foreach (array_slice($sheet, 1) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($map as $key => $idx) {
                $item[$key] = (string) ($row[$idx] ?? '');
            }
            $out[] = $item;
        }

        return $out;
    }

    private function resolveType(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            $fallback = ContentType::query()->orderBy('id')->value('name');

            return $fallback ? (string) $fallback : 'Post';
        }

        $existing = ContentType::query()
            ->where('name', $type)
            ->orWhere('slug', str($type)->slug()->toString())
            ->value('name');

        return $existing ? (string) $existing : $type;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[|;,]+/', $value) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn ($part) => trim((string) $part),
            $parts
        ), static fn ($part) => $part !== '')));
    }
}
