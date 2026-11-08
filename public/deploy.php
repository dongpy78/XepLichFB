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

/**
 * Sao lưu toàn bộ database thành file .sql.gz (nhập lại được bằng phpMyAdmin) trong storage/app/deploy-backups – thư mục ngoài web.
 * Dùng PDO trực tiếp để vẫn sao lưu được khi code hiện tại đang lỗi. Giữ 7 bản gần nhất.
 */
function backup_database(string $basePath): string
{
    $env = fn (string $key, string $default = '') => readEnvValue($basePath . '/.env', $key) ?? $default;
    $driver = $env('DB_CONNECTION', 'mysql');

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException("chưa hỗ trợ sao lưu {$driver}");
    }

    $dir = $basePath . '/storage/app/deploy-backups';

    if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
        throw new RuntimeException('không tạo được thư mục sao lưu');
    }

    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306'), $env('DB_DATABASE')), $env('DB_USERNAME'), $env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
    $target = $dir . '/db-' . date('Ymd-His') . '.sql.gz';
    $tmp = $target . '.tmp';
    $gz = gzopen($tmp, 'wb6');

    if (! $gz) {
        throw new RuntimeException('không ghi được file sao lưu');
    }

    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach ($tables as $table) {
        $name = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$name}")->fetchAll(PDO::FETCH_NUM)[0][1];
        gzwrite($gz, "\nDROP TABLE IF EXISTS {$name};\n{$create};\n");
        $rows = $pdo->query("SELECT * FROM {$name}", PDO::FETCH_NUM);
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';

            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO {$name} VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }

        $rows->closeCursor();

        if ($batch) {
            gzwrite($gz, "INSERT INTO {$name} VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    rename($tmp, $target);
    $backups = glob($dir . '/db-*.sql.gz') ?: [];
    sort($backups);

    foreach (array_slice($backups, 0, max(0, count($backups) - 7)) as $old) {
        @unlink($old);
    }

    return basename($target) . ' (' . round(filesize($target) / 1048576, 1) . ' MB, ' . count($tables) . ' bảng)';
}

/** Website thật không chạy chế độ gỡ lỗi: tự tắt APP_DEBUG trong .env trên hosting. */
function disable_debug(string $envFile): bool
{
    $content = @file_get_contents($envFile);

    if ($content === false) {
        return false;
    }

    $updated = preg_replace('/^(\s*APP_DEBUG\s*=\s*)["\']?(true|1|on)["\']?(\s*)$/mi', '${1}false${3}', $content);

    return $updated !== null && $updated !== $content && file_put_contents($envFile, $updated, LOCK_EX) !== false;
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

// Bảo trì trong lúc cập nhật: file storage/framework/down là chế độ bảo trì của Laravel.
// Luôn gỡ khi script kết thúc (kể cả lỗi); nếu quản trị đã bật bảo trì từ trước thì giữ nguyên.
$downFile = $basePath . '/storage/framework/down';

if (! is_file($downFile)) {
    @file_put_contents($downFile, json_encode(['except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => 15, 'secret' => null, 'status' => 503, 'template' => null]));
    register_shutdown_function(static fn () => @unlink($downFile));
    echo "Đã bật chế độ bảo trì\n";
}

// Sao lưu database trước khi thay code và migrate. Không sao lưu được thì dừng, website giữ nguyên.
if (($_SERVER['HTTP_X_DEPLOY_SKIP_BACKUP'] ?? '') === '1') {
    echo "CẢNH BÁO: bỏ qua sao lưu database theo yêu cầu\n";
} else {
    try {
        echo 'Đã sao lưu database: ' . backup_database($basePath) . "\n";
    } catch (Throwable $e) {
        fail(500, 'Không sao lưu được database, chưa thay đổi gì trên website: ' . $e->getMessage());
    }
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

if (disable_debug($basePath . '/.env')) {
    echo "Đã tắt APP_DEBUG trong .env\n";
}

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

// Chép lại JS/CSS vào <thư mục web>/vendor/core (thư mục này không có trong git).
// Chép thẳng từ platform/{core,packages,plugins}/*/public – không phụ thuộc lệnh cms:publish:assets
// (lệnh đó từng chạy không lỗi nhưng không cập nhật file trên hosting).
function copy_dir(string $from, string $to): int
{
    $count = 0;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($items as $item) {
        $target = $to . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($from) + 1);

        if ($item->isDir()) {
            is_dir($target) || mkdir($target, 0755, true);
        } elseif (copy($item->getPathname(), $target)) {
            $count++;
        } else {
            echo "Cảnh báo: không chép được {$target}\n";
        }
    }

    return $count;
}

$webRoot = __DIR__;
$copied = 0;

foreach (['core', 'packages', 'plugins'] as $group) {
    foreach (glob($basePath . "/platform/{$group}/*/public", GLOB_ONLYDIR) ?: [] as $source) {
        $name = basename(dirname($source));
        $target = "{$webRoot}/vendor/core/{$group}/{$name}";
        is_dir($target) || mkdir($target, 0755, true);
        $copied += copy_dir($source, $target);
    }
}

echo "Đã cập nhật JS/CSS: {$copied} file vào {$webRoot}/vendor/core\n";

$check = $webRoot . '/vendor/core/plugins/restaurant-roster/js/roster.js';
echo 'Kiểm tra roster.js: ' . (is_file($check) ? filesize($check) . ' byte, sửa lúc ' . date('d/m/Y H:i', filemtime($check)) : 'KHÔNG CÓ FILE') . "\n";

echo "DEPLOY OK\n";
