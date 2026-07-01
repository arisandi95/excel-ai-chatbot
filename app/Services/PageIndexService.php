<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Service untuk PageIndex-style retrieval pada data Excel.
 *
 * Service ini membuat representasi tree dari file Excel dan memilih
 * node paling relevan berdasarkan pertanyaan user.
 */
class PageIndexService
{
    protected ExcelFileService $excelFileService;
    protected string $cacheDirectory;
    protected bool $cacheEnabled;
    protected int $cacheTtl;
    protected int $maxChildrenPerNode;
    protected bool $enableCategoryDetection;
    protected bool $enableNumericSummary;
    protected int $childPreviewLimit;
    protected array $categoryFieldCandidates = ['kategori', 'category', 'jenis', 'group', 'kelompok'];

    public function __construct(ExcelFileService $excelFileService)
    {
        $this->excelFileService = $excelFileService;
        $this->cacheDirectory = storage_path('app/cache/pageindex');

        $config = config('services.rag.pageindex', []);
        $this->cacheEnabled = filter_var($config['cache_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? 3600);
        $this->maxChildrenPerNode = (int) ($config['max_children_per_node'] ?? 50);
        $this->enableCategoryDetection = filter_var($config['enable_category_detection'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->enableNumericSummary = filter_var($config['enable_numeric_summary'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->childPreviewLimit = (int) ($config['child_preview_limit'] ?? 20);
    }

    public function buildTree(string $filename): array
    {
        $cachePath = $this->getCachePath($filename);

        if ($this->cacheEnabled && $this->hasValidCache($cachePath)) {
            try {
                return json_decode(File::get($cachePath), true) ?? [];
            } catch (\Throwable $e) {
                Log::warning('Gagal membaca cache PageIndex', ['file' => $filename, 'error' => $e->getMessage()]);
            }
        }

        $tree = $this->parseExcelToTree($filename);

        if ($this->cacheEnabled) {
            try {
                if (!File::isDirectory($this->cacheDirectory)) {
                    File::makeDirectory($this->cacheDirectory, 0755, true);
                }
                File::put($cachePath, json_encode($tree, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                Log::warning('Gagal menulis cache PageIndex', ['file' => $filename, 'error' => $e->getMessage()]);
            }
        }

        return $tree;
    }

    public function reasoningRetrieval(string $question, array $tree): array
    {
        $nodes = $this->flattenTreeNodes($tree);
        if (empty($nodes)) {
            return [];
        }

        $scored = [];
        foreach ($nodes as $node) {
            $score = $this->scoreNode($question, $node);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'node' => $node];
            }
        }

        if (empty($scored)) {
            return [];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topNodes = array_slice($scored, 0, 5);

        return $this->extractChunksFromNodes(array_column($topNodes, 'node'));
    }

    protected function parseExcelToTree(string $filename): array
    {
        $filePath = $this->excelFileService->getUploadPath() . DIRECTORY_SEPARATOR . $filename;
        $spreadsheet = IOFactory::load($filePath);

        $root = [
            'id' => $filename,
            'type' => 'file',
            'title' => $filename,
            'summary' => 'File Excel yang diproses untuk chat AI.',
            'children' => [],
            'file' => $filename,
        ];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetRows = $this->extractRowsFromSheet($sheet, $filename);
            $sheetTitle = $sheet->getTitle();
            $headers = $sheetRows['headers'];
            $rows = $sheetRows['rows'];

            $sheetNode = [
                'id' => $filename . '::' . $sheetTitle,
                'type' => 'sheet',
                'title' => $sheetTitle,
                'summary' => $this->buildSummaryForSheet($rows, $headers),
                'children' => [],
                'file' => $filename,
                'sheet' => $sheetTitle,
                'headers' => $headers,
            ];

            $categoryColumn = $this->enableCategoryDetection ? $this->findCategoryColumn($headers) : null;
            $categories = $this->groupRowsByCategory($rows, $categoryColumn);

            foreach ($categories as $categoryName => $categoryRows) {
                if ($categoryName === '__all_data__') {
                    foreach ($categoryRows as $rowNode) {
                        $sheetNode['children'][] = $rowNode;
                    }
                    continue;
                }

                $categoryNode = [
                    'id' => $sheetNode['id'] . '::category::' . $this->normalizeText($categoryName),
                    'type' => 'category',
                    'title' => 'Kategori: ' . $categoryName,
                    'summary' => 'Bagian kategori ' . $categoryName . ' dengan ' . count($categoryRows) . ' baris.',
                    'children' => array_values($categoryRows),
                    'file' => $filename,
                    'sheet' => $sheetTitle,
                    'category' => $categoryName,
                ];

                if ($this->enableNumericSummary) {
                    $categoryNode['metadata'] = [
                        'numeric_summary' => $this->buildNumericSummary($categoryRows),
                        'row_count' => count($categoryRows),
                    ];
                }

                $sheetNode['children'][] = $categoryNode;
            }

            $root['children'][] = $sheetNode;
        }

        return $root;
    }

    protected function extractRowsFromSheet(Worksheet $sheet, string $filename): array
    {
        $rows = [];
        $headers = [];
        $isHeaderRow = true;

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];

            foreach ($cellIterator as $cell) {
                $rowData[] = trim((string) $cell->getCalculatedValue());
            }

            if ($isHeaderRow) {
                $headers = array_map(fn ($value) => $value === '' ? 'kolom' : $value, $rowData);
                $isHeaderRow = false;
                continue;
            }

            if (empty(array_filter($rowData, fn ($value) => $value !== ''))) {
                continue;
            }

            $rowMap = ['__sheet' => $sheet->getTitle(), '__file' => $filename];
            foreach ($rowData as $index => $value) {
                $key = $headers[$index] ?? 'kolom_' . ($index + 1);
                $rowMap[$key] = $value;
            }

            $rowIndex = count($rows) + 1;
            $rows[] = [
                'id' => $filename . '::' . $sheet->getTitle() . '::row::' . $rowIndex,
                'type' => 'row',
                'title' => 'Baris ' . $rowIndex,
                'content' => $this->buildRowText($rowMap),
                'row_map' => $rowMap,
                'file' => $filename,
                'sheet' => $sheet->getTitle(),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    protected function findCategoryColumn(array $headers): ?string
    {
        foreach ($headers as $header) {
            $normalized = mb_strtolower($header);
            foreach ($this->categoryFieldCandidates as $candidate) {
                if (str_contains($normalized, $candidate)) {
                    return $header;
                }
            }
        }

        return null;
    }

    protected function groupRowsByCategory(array $rows, ?string $categoryColumn): array
    {
        if ($categoryColumn === null) {
            return ['__all_data__' => $rows];
        }

        $grouped = [];

        foreach ($rows as $row) {
            $categoryValue = $row['row_map'][$categoryColumn] ?? 'Uncategorized';
            $categoryKey = $categoryValue === '' ? 'Uncategorized' : $categoryValue;
            $grouped[$categoryKey][] = $row;
        }

        return $grouped;
    }

    protected function buildRowText(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['__sheet', '__file'], true)) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $parts[] = "{$key}: {$value}";
        }

        return implode(' | ', $parts);
    }

    protected function buildSummaryForSheet(array $rows, array $headers): string
    {
        $rowCount = count($rows);
        $headerList = implode(', ', array_slice($headers, 0, 10));
        $summary = "Sheet ini memiliki {$rowCount} baris";

        if (!empty($headers)) {
            $summary .= " dan kolom: {$headerList}";
        }

        return $summary;
    }

    protected function buildNumericSummary(array $rows): array
    {
        $aggregates = [];
        $numericFields = [];

        foreach ($rows as $row) {
            foreach ($row['row_map'] as $field => $value) {
                if (in_array($field, ['__sheet', '__file'], true)) {
                    continue;
                }

                $cleanValue = str_replace(['.', ','], ['', '.'], trim($value));
                if (is_numeric($cleanValue)) {
                    $numericFields[$field][] = (float) $cleanValue;
                }
            }
        }

        foreach ($numericFields as $field => $values) {
            if (empty($values)) {
                continue;
            }

            $aggregates[$field] = [
                'min' => min($values),
                'max' => max($values),
                'avg' => array_sum($values) / count($values),
                'sum' => array_sum($values),
                'count' => count($values),
            ];
        }

        return $aggregates;
    }

    protected function flattenTreeNodes(array $node): array
    {
        $results = [];

        if (isset($node['type']) && $node['type'] !== 'file') {
            $results[] = $node;
        }

        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $results = array_merge($results, $this->flattenTreeNodes($child));
            }
        }

        return $results;
    }

    protected function scoreNode(string $question, array $node): int
    {
        $questionTerms = $this->tokenizeText($question);
        if (empty($questionTerms)) {
            return 0;
        }

        $content = trim(($node['title'] ?? '') . ' ' . ($node['summary'] ?? '') . ' ' . ($node['content'] ?? ''));
        $content = $this->normalizeText($content);
        $score = 0;

        foreach ($questionTerms as $term) {
            if ($term === '') {
                continue;
            }

            if (str_contains($content, $term)) {
                $score += 2;
            }

            if (isset($node['title']) && str_contains($this->normalizeText($node['title']), $term)) {
                $score += 4;
            }

            if (isset($node['summary']) && str_contains($this->normalizeText($node['summary']), $term)) {
                $score += 3;
            }

            if ($node['type'] === 'row' && isset($node['content']) && str_contains($this->normalizeText($node['content']), $term)) {
                $score += 2;
            }
        }

        return $score;
    }

    protected function tokenizeText(string $text): array
    {
        $normalized = $this->normalizeText($text);
        return array_filter(array_map('trim', explode(' ', $normalized)), fn ($value) => $value !== '');
    }

    protected function normalizeText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/[^
\p{L}\p{N}]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return trim($normalized);
    }

    protected function extractChunksFromNodes(array $nodes): array
    {
        $chunks = [];

        foreach ($nodes as $index => $node) {
            $chunkText = $this->getNodeContent($node);
            if ($chunkText === '') {
                continue;
            }

            $chunks[] = [
                'file' => $node['file'] ?? '',
                'sheet' => $node['sheet'] ?? ($node['type'] === 'sheet' ? $node['title'] : ''),
                'chunk_id' => $index + 1,
                'text' => $chunkText,
            ];
        }

        return $chunks;
    }

    protected function getNodeContent(array $node): string
    {
        $content = [];

        if (isset($node['title'])) {
            $content[] = $node['title'];
        }

        if (isset($node['summary'])) {
            $content[] = $node['summary'];
        }

        if (isset($node['content']) && $node['content'] !== '') {
            $content[] = $node['content'];
        }

        if (empty($content) && !empty($node['children']) && is_array($node['children'])) {
            $content[] = $this->getFirstChildTexts($node, $this->childPreviewLimit);
        }

        return trim(implode("\n", array_filter($content)));
    }

    protected function getFirstChildTexts(array $node, int $limit = 3): string
    {
        $texts = [];

        foreach ($node['children'] as $child) {
            if (isset($child['content']) && $child['content'] !== '') {
                $texts[] = $child['content'];
            }
            if (count($texts) >= $limit) {
                break;
            }
        }

        return implode("\n", $texts);
    }

    protected function getCachePath(string $filename): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . md5($filename) . '.json';
    }

    protected function hasValidCache(string $cachePath): bool
    {
        if (!File::exists($cachePath)) {
            return false;
        }

        if ($this->cacheTtl <= 0) {
            return true;
        }

        return (time() - File::lastModified($cachePath)) < $this->cacheTtl;
    }
}
