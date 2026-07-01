<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\ExcelFileService;
use App\Services\PageIndexService;

echo "Starting PageIndex cache builder...\n";

/** @var ExcelFileService $excel */
$excel = $app->make(ExcelFileService::class);
$result = $excel->getExcelFiles();

if (!is_array($result) || ($result['success'] ?? false) !== true) {
    echo "No Excel files found or uploads missing:\n";
    echo ($result['error'] ?? 'No files or uploads folder is empty.') . "\n";
    exit(1);
}

$files = $result['files'] ?? [];
if (empty($files)) {
    echo "No Excel files to process.\n";
    exit(1);
}

/** @var PageIndexService $pageIndex */
$pageIndex = $app->make(PageIndexService::class);
$cacheDir = storage_path('app/cache/pageindex');
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

foreach ($files as $file) {
    echo "Building PageIndex for: {$file}\n";
    try {
        $tree = $pageIndex->buildTree($file);
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . md5($file) . '.json';
        if (file_exists($cachePath)) {
            echo "  -> Cache written: {$cachePath}\n";
        } else {
            echo "  -> Warning: cache file not found after build for {$file}\n";
        }
    } catch (Throwable $e) {
        echo "  -> Error building {$file}: " . $e->getMessage() . "\n";
    }
}

echo "PageIndex cache build complete.\n";

return 0;
