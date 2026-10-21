<?php

/**
 * Giải nén bản build do GitHub Actions upload lên (xem .github/workflows/main.yml).
 *
 * Gọi: POST https://<domain>/deploy.php với header "X-Deploy-Token: <DEPLOY_TOKEN>".
 * DEPLOY_TOKEN đặt trong file .env trên hosting (không có trong git).
 */

ignore_user_abort(true);
@set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

$basePath = dirname(__DIR__);
$zipPath = $basePath . '/.deploy/release.zip';

function fail(int $code, string $message): never
{
    http_response_code($code);
    echo 'ERROR: ' . $message . "\n";
    exit;
}

function readEnvValue(string $envFile, string $key): ?string
{
    if (! is_readable($envFile)) {
        return null;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)\s*$/', $line, $m)) {
            return trim($m[1], " \t\"'");
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(404, 'Not found');
}

$expected = readEnvValue($basePath . '/.env', 'DEPLOY_TOKEN');
$given = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if (! $expected || strlen($expected) < 32) {
    fail(500, 'DEPLOY_TOKEN chưa được cấu hình (cần ít nhất 32 ký tự) trong .env');
}

if (! hash_equals($expected, $given)) {
    fail(403, 'Sai token');
}

if (! class_exists(ZipArchive::class)) {
    fail(500, 'Hosting chưa bật PHP extension zip');
}

if (! is_file($zipPath)) {
    fail(500, 'Không tìm thấy file ' . $zipPath);
}

$zip = new ZipArchive();

if ($zip->open($zipPath) !== true) {
    fail(500, 'Không mở được file zip');
}

$count = $zip->numFiles;

if (! $zip->extractTo($basePath)) {
    $zip->close();
    fail(500, 'Giải nén thất bại');
}

$zip->close();
@unlink($zipPath);
echo "Đã giải nén {$count} file\n";

// Xóa cache để Laravel đọc lại code mới
foreach (glob($basePath . '/bootstrap/cache/*.php') ?: [] as $file) {
    @unlink($file);
}

foreach (glob($basePath . '/storage/framework/views/*.php') ?: [] as $file) {
    @unlink($file);
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "Đã xóa cache\n";

try {
    require $basePath . '/vendor/autoload.php';
    $app = require $basePath . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('optimize:clear');
    echo $kernel->output();
} catch (Throwable $e) {
    // Không chặn deploy: cache file đã được xóa ở trên
    echo 'Cảnh báo: không chạy được optimize:clear - ' . $e->getMessage() . "\n";
}

// Cập nhật cấu trúc database theo code mới (migration của app và các plugin).
try {
    $kernel->call('migrate', ['--force' => true]);
    echo $kernel->output();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: chạy migration thất bại - ' . $e->getMessage() . "\n";
    exit;
}

// Chép lại JS/CSS của plugin vào public/vendor/core (thư mục này không có trong git).
try {
    $kernel->call('cms:publish:assets');
    echo "Đã cập nhật JS/CSS\n";
} catch (Throwable $e) {
    echo 'Cảnh báo: không publish được asset - ' . $e->getMessage() . "\n";
}

echo "DEPLOY OK\n";
