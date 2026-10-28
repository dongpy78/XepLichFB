<?php

namespace Tests\Feature\Roster;

use Tests\TestCase;

/**
 * Mọi giao diện Blade của plugin xếp lịch biên dịch ra PHP hợp lệ (lỗi cú pháp Blade chỉ lộ khi mở trang trên server).
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_all_roster_views_compile_to_valid_php(): void
    {
        $compiler = app('blade.compiler');
        $dir = base_path('platform/plugins/restaurant-roster/resources/views');
        $bad = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blade') . '.php';
            file_put_contents($tmp, $compiler->compileString(file_get_contents($file->getPathname())));
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
            @unlink($tmp);

            if ($code !== 0) {
                $bad[] = substr($file->getPathname(), strlen($dir) + 1) . ': ' . implode(' ', array_slice($output, 0, 2));
            }

            $output = [];
        }

        $this->assertSame([], $bad, 'Giao diện lỗi cú pháp');
    }
}
