<?php

namespace Botble\RestaurantRoster\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Xuất lịch tuần (từ một bản chụp – phiên bản đã công bố hoặc bản nháp) ra Excel (.xlsx), PDF (.pdf), ảnh PNG.
 * Cả ba định dạng dùng cùng bản chụp nên thống nhất nhân viên, khu, ngày, mã ca, OFF.
 */
class ScheduleExportService
{
    public function __construct(private readonly ScheduleSnapshotService $snapshots)
    {
    }

    // ------------------------------------------------------------------ Excel

    public function xlsx(array $snapshot): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Lịch tuần');
        $days = $snapshot['days'];
        $lastCol = $this->col(4 + count($days));
        $draft = ! isset($snapshot['version']);

        $sheet->setCellValue('A1', 'LỊCH LÀM VIỆC TUẦN' . ($draft ? ' – BẢN NHÁP' : ''));
        $sheet->setCellValue('A2', $this->snapshots->period($snapshot) . ' · ' . $snapshot['scope_label']);
        $sheet->setCellValue('A3', $this->snapshots->caption($snapshot));
        foreach ([1, 2, 3] as $r) {
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($draft ? 'C0392B' : '000000');
        $sheet->getStyle('A2:A3')->getFont()->setSize(11);

        $header = ['STT', 'Mã NV', 'Họ và tên', 'Chức vụ', ...array_column($days, 'label')];
        $sheet->fromArray($header, null, 'A5');
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

        $r = 6;
        $n = 0;

        foreach ($snapshot['sections'] as $section) {
            $sheet->setCellValue("A{$r}", mb_strtoupper($section['label']));
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->getStyle("A{$r}")->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCEFD9']]]);
            $r++;

            foreach ($section['rows'] as $row) {
                $sheet->fromArray([++$n, $row['code'], $row['name'], $row['position'], ...$row['cells']], null, "A{$r}", true);

                foreach ($row['cells'] as $k => $cell) {
                    if (! empty($row['offs'][$k])) {
                        $sheet->getStyle($this->col(5 + $k) . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
                    }
                }

                $r++;
            }
        }

        $last = $r - 1;
        $sheet->getStyle("A5:{$lastCol}{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("E5:{$lastCol}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A5:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Chú thích ca.
        $r += 1;
        $sheet->setCellValue("A{$r}", 'Chú thích: ' . implode(' · ', array_map(fn ($code, $time) => "{$code} {$time}", array_keys($snapshot['legend']), $snapshot['legend'])) . ' · OFF: nghỉ');
        $sheet->mergeCells("A{$r}:{$lastCol}{$r}");

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(max(24, min(40, 2 + $this->longest($snapshot, 'name'))));
        $sheet->getColumnDimension('D')->setWidth(max(14, min(34, 2 + $this->longest($snapshot, 'position'))));

        foreach (array_keys($days) as $k) {
            $sheet->getColumnDimension($this->col(5 + $k))->setWidth(max(11, 2 + $this->longestCell($snapshot, $k)));
        }

        $sheet->freezePane('E6');
        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $setup->setPrintArea("A1:{$lastCol}{$r}");
        $setup->setRowsToRepeatAtTopByStartAndEnd(5, 5);

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    // ------------------------------------------------------------------ PDF

    public function pdf(array $snapshot): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $cache = storage_path('app/roster-pdf-fonts');
        is_dir($cache) || @mkdir($cache, 0755, true);
        $options->set('fontCache', $cache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('plugins/restaurant-roster::schedule.pdf', [
            'snapshot' => $snapshot,
            'period' => $this->snapshots->period($snapshot),
            'caption' => $this->snapshots->caption($snapshot),
            'draft' => ! isset($snapshot['version']),
        ])->render(), 'UTF-8');
        // Bảng rộng (7 ngày + tên + chức vụ): khổ A4 ngang; bảng dài tự sang trang, tiêu đề cột lặp lại (thead).
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    // ------------------------------------------------------------------ PNG

    /**
     * Ảnh PNG: một ảnh duy nhất cho cả tuần (quản lý 30/10/2026 – không chia phần), thiết kế theo màu thương hiệu
     * (navy + vàng gold), mỗi loại ca một màu (sáng / trưa / chiều / đêm), OFF xám, nghỉ phép hồng; dòng xen kẽ dễ dò.
     */
    public function png(array $snapshot): string
    {
        $sections = array_values(array_filter($snapshot['sections'], fn ($s) => $s['rows']));

        if (! $sections) {
            throw new RuntimeException('Tuần này chưa có nhân viên nào trên bảng xếp ca.');
        }

        [$regular, $bold] = $this->fonts();
        $days = $snapshot['days'];
        $draft = ! isset($snapshot['version']);
        $rows = array_merge(...array_map(fn ($s) => $s['rows'], $sections));

        // Kích thước (px) – đủ lớn để đọc rõ trên điện thoại khi gửi Zalo.
        $pad = 40;
        $bannerH = 176;
        $headH = 70;
        $sectionH = 50;
        $rowH = 64;
        $colNo = 64;
        $colName = max(340, min(480, 44 + max($this->textWidth($bold, 19, $this->longestText($rows, 'name')), $this->textWidth($regular, 14, $this->longestText($rows, 'position')))));
        $colDay = 136;
        $tableW = $colNo + $colName + $colDay * count($days);
        $width = $pad * 2 + $tableW;
        $tableTop = $bannerH + 30;
        $tableH = $headH + $sectionH * count($sections) + $rowH * count($rows);
        $height = $tableTop + $tableH + 120;

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        $c = fn (string $hex) => imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $bg = $c('F4F6FA');
        $white = $c('FFFFFF');
        $navy = $c('0A2540');
        $navyDeep = $c('061A30');
        $gold = $c('E0A96D');
        $goldWash = $c('FDF3E7');
        $ink = $c('1B2330');
        $muted = $c('6B7686');
        $line = $c('E3E8EF');
        $zebra = $c('F8FAFC');
        $weekendHead = $c('E4EBF5');
        $red = $c('D64545');
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Băng tiêu đề navy + vạch vàng.
        imagefilledrectangle($img, 0, 0, $width, $bannerH, $navy);
        imagefilledrectangle($img, 0, $bannerH - 6, $width, $bannerH, $gold);
        imagefilledrectangle($img, $pad, 40, $pad + 8, 112, $gold);
        imagettftext($img, 34, 0, $pad + 26, 76, $white, $bold, 'LỊCH LÀM VIỆC TUẦN');
        imagettftext($img, 20, 0, $pad + 26, 112, $gold, $bold, $this->snapshots->period($snapshot));
        imagettftext($img, 14, 0, $pad + 26, 144, $c('B9C6D8'), $regular, $this->fit($regular, 14, $snapshot['scope_label'] . ' · ' . $this->snapshots->caption($snapshot), $width - $pad * 2 - 260));

        // Nhãn phiên bản / bản nháp góc phải.
        $tag = $draft ? 'BẢN NHÁP' : 'PHIÊN BẢN v' . $snapshot['version'];
        $tagW = $this->textWidth($bold, 18, $tag) + 44;
        $this->pill($img, $width - $pad - $tagW, 52, $width - $pad, 98, $draft ? $red : $gold);
        imagettftext($img, 18, 0, $width - $pad - $tagW + 22, 83, $draft ? $white : $navyDeep, $bold, $tag);

        // Thẻ bảng (nền trắng, viền nhẹ).
        $x0 = $pad;
        $x1 = $pad + $tableW;
        $this->roundRect($img, $x0 - 1, $tableTop - 1, $x1 + 1, $tableTop + $tableH + 1, 14, $line);
        $this->roundRect($img, $x0, $tableTop, $x1, $tableTop + $tableH, 14, $white);

        // Hàng tiêu đề: "Thứ" đậm + ngày nhỏ; thứ 7 / CN nền đậm hơn.
        $y = $tableTop;
        $this->roundRect($img, $x0, $y, $x1, $y + $headH, 14, $c('EEF2F8'));
        imagefilledrectangle($img, $x0, $y + 14, $x1, $y + $headH, $c('EEF2F8'));
        $this->cellText($img, $bold, 15, $muted, $x0, $colNo, $y, $headH, '#', true);
        $this->cellText($img, $bold, 16, $navy, $x0 + $colNo, $colName, $y, $headH, 'NHÂN VIÊN');

        foreach ($days as $k => $day) {
            $cx = $x0 + $colNo + $colName + $colDay * $k;
            [$dow, $date] = array_pad(explode(' ', $day['label'], 2), 2, '');

            if (in_array($dow, ['T7', 'CN'], true)) {
                if ($k === count($days) - 1) {
                    // Cột cuối: giữ góc bo tròn của thẻ bảng.
                    $this->roundRect($img, $cx, $y, $cx + $colDay, $y + $headH, 14, $weekendHead);
                    imagefilledrectangle($img, $cx, $y + 14, $cx + $colDay, $y + $headH, $weekendHead);
                    imagefilledrectangle($img, $cx, $y, $cx + $colDay - 14, $y + $headH, $weekendHead);
                } else {
                    imagefilledrectangle($img, $cx, $y, $cx + $colDay, $y + $headH, $weekendHead);
                }
            }

            $this->cellText($img, $bold, 18, $navy, $cx, $colDay, $y - 12, $headH, $dow, true);
            $this->cellText($img, $regular, 13, $muted, $cx, $colDay, $y + 14, $headH, $date, true);
        }

        $y += $headH;
        $n = 0;

        foreach ($sections as $section) {
            // Băng tên khu: nền vàng nhạt + vạch vàng trái.
            imagefilledrectangle($img, $x0, $y, $x1, $y + $sectionH, $goldWash);
            imagefilledrectangle($img, $x0, $y, $x0 + 6, $y + $sectionH, $gold);
            $this->cellText($img, $bold, 17, $navy, $x0 + 16, $x1 - $x0 - 16, $y, $sectionH, mb_strtoupper($section['label']) . '  ·  ' . count($section['rows']) . ' người');
            $y += $sectionH;

            foreach ($section['rows'] as $i => $row) {
                if ($i % 2 === 1) {
                    imagefilledrectangle($img, $x0, $y, $x1, $y + $rowH, $zebra);
                }

                imageline($img, $x0, $y, $x1, $y, $line);
                $this->cellText($img, $regular, 15, $muted, $x0, $colNo, $y, $rowH, (string) ++$n, true);
                // Tên đậm + chức vụ nhỏ bên dưới.
                imagettftext($img, 19, 0, $x0 + $colNo + 12, $y + 30, $ink, $bold, $this->fit($bold, 19, $row['name'], $colName - 24));
                imagettftext($img, 13, 0, $x0 + $colNo + 12, $y + 52, $muted, $regular, $this->fit($regular, 13, $row['position'], $colName - 24));

                foreach ($row['cells'] as $k => $cell) {
                    if ($cell === '') {
                        continue;
                    }

                    $cx = $x0 + $colNo + $colName + $colDay * $k;
                    [$fill, $text] = $this->shiftColors($snapshot, $cell, ! empty($row['offs'][$k]));
                    $this->pill($img, $cx + 12, $y + 13, $cx + $colDay - 12, $y + $rowH - 13, $c($fill));
                    $this->cellText($img, $bold, mb_strlen($cell) > 6 ? 14 : 17, $c($text), $cx, $colDay, $y, $rowH, $cell, true);
                }

                $y += $rowH;
            }
        }

        // Đường kẻ dọc nhẹ giữa các ngày.
        foreach (range(0, count($days)) as $k) {
            $cx = $x0 + $colNo + $colName + $colDay * $k;
            imageline($img, $cx, $tableTop + $headH, $cx, $tableTop + $tableH, $line);
        }

        // Chú thích: chip màu từng ca + OFF.
        $lx = $pad;
        $ly = $tableTop + $tableH + 36;
        imagettftext($img, 15, 0, $lx, $ly + 20, $muted, $bold, 'Chú thích');
        $lx += $this->textWidth($bold, 15, 'Chú thích') + 24;

        foreach ([...$snapshot['legend'], 'OFF' => 'nghỉ'] as $code => $time) {
            [$fill, $text] = $this->shiftColors($snapshot, (string) $code, $code === 'OFF');
            $chipW = $this->textWidth($bold, 15, (string) $code) + 28;

            if ($lx + $chipW + $this->textWidth($regular, 14, (string) $time) + 30 > $width - $pad) {
                break;
            }

            $this->pill($img, $lx, $ly, $lx + $chipW, $ly + 30, $c($fill));
            imagettftext($img, 15, 0, $lx + 14, $ly + 22, $c($text), $bold, (string) $code);
            imagettftext($img, 14, 0, $lx + $chipW + 10, $ly + 21, $muted, $regular, (string) $time);
            $lx += $chipW + $this->textWidth($regular, 14, (string) $time) + 34;
        }

        imagettftext($img, 13, 0, $pad, $ly + 64, $c('9AA4B2'), $regular, 'Selectum Noa · F&B · Xếp lịch nhà hàng');

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * Màu ô ca theo giờ bắt đầu (lấy từ chú thích): sáng xanh dương, trưa xanh ngọc, chiều cam, đêm tím; OFF xám; nghỉ phép hồng.
     *
     * @return array{0: string, 1: string} [nền, chữ] (hex)
     */
    private function shiftColors(array $snapshot, string $cell, bool $off): array
    {
        if ($off) {
            return $cell === 'OFF' ? ['E9ECF1', '7A8494'] : ['FCE4EC', 'AD1457'];
        }

        $code = (string) preg_replace('/[(+].*$/u', '', $cell);
        $hour = isset($snapshot['legend'][$code]) ? (int) substr((string) $snapshot['legend'][$code], 0, 2) : null;

        return match (true) {
            $hour === null => ['E8EEF6', '0A2540'],
            $hour >= 19 || $hour < 4 => ['E8E3FB', '4527A0'],
            $hour < 8 => ['DCEBFF', '0B4F9C'],
            $hour < 12 => ['D7F2EC', '0B6E5A'],
            default => ['FFE9CF', '9A4B00'],
        };
    }

    private function roundRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = max(0, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);

        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    /** Viên thuốc (bo tròn hai đầu). */
    private function pill($img, int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        $this->roundRect($img, $x1, $y1, $x2, $y2, (int) (($y2 - $y1) / 2), $color);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: string, 1: string} font TTF có tiếng Việt (DejaVu Sans của dompdf, có sẵn trên hosting). */
    private function fonts(): array
    {
        $dirs = [base_path('vendor/dompdf/dompdf/lib/fonts'), base_path('platform/plugins/restaurant-roster/resources/fonts')];

        foreach ($dirs as $dir) {
            if (is_file("{$dir}/DejaVuSans.ttf") && is_file("{$dir}/DejaVuSans-Bold.ttf")) {
                return ["{$dir}/DejaVuSans.ttf", "{$dir}/DejaVuSans-Bold.ttf"];
            }
        }

        throw new RuntimeException('Không tìm thấy font DejaVu Sans để tạo ảnh PNG.');
    }

    private function cellText($img, string $font, int $size, int $color, int $x, int $w, int $y, int $h, string $text, bool $center = false): void
    {
        $text = $this->fit($font, $size, $text, $w - 16);
        $tw = $this->textWidth($font, $size, $text);
        $tx = $center ? (int) ($x + ($w - $tw) / 2) : $x + 10;
        imagettftext($img, $size, 0, $tx, (int) ($y + $h / 2 + $size * 0.38), $color, $font, $text);
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (int) abs($box[2] - $box[0]);
    }

    /** Cắt bớt (…) nếu chữ dài hơn ô – không để chữ tràn / chồng lên ô bên cạnh. */
    private function fit(string $font, int $size, string $text, int $max): string
    {
        if ($this->textWidth($font, $size, $text) <= $max) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->textWidth($font, $size, $text . '…') > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return $text . '…';
    }

    private function longestText(array $rows, string $field): string
    {
        return array_reduce($rows, fn ($carry, $r) => mb_strlen($r[$field]) > mb_strlen($carry) ? $r[$field] : $carry, '');
    }

    private function longest(array $snapshot, string $field): int
    {
        return max([0, ...array_map(fn ($s) => max([0, ...array_map(fn ($r) => mb_strlen($r[$field]), $s['rows'])]), $snapshot['sections'])]);
    }

    private function longestCell(array $snapshot, int $k): int
    {
        return max([0, ...array_map(fn ($s) => max([0, ...array_map(fn ($r) => mb_strlen($r['cells'][$k] ?? ''), $s['rows'])]), $snapshot['sections'])]);
    }

    private function col(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
