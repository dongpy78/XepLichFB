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
    /** Số nhân viên tối đa mỗi ảnh PNG (để chữ đủ lớn khi xem trên điện thoại). */
    public const PNG_ROWS_PER_PART = 25;

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
     * Chia ảnh: theo khu (outlet / nhóm lịch), khu nhiều người thì chia mỗi phần tối đa PNG_ROWS_PER_PART người.
     *
     * @return array<int, array{section: string, rows: array, start: int, part: int, total: int}>
     */
    public function pngParts(array $snapshot): array
    {
        $parts = [];
        $n = 0;

        foreach ($snapshot['sections'] as $section) {
            $chunks = array_chunk($section['rows'], self::PNG_ROWS_PER_PART);

            foreach ($chunks as $k => $rows) {
                $parts[] = ['section' => $section['label'] . (count($chunks) > 1 ? sprintf(' (%d/%d)', $k + 1, count($chunks)) : ''), 'rows' => $rows, 'start' => $n];
                $n += count($rows);
            }
        }

        $total = count($parts);

        return array_map(fn ($p, $i) => [...$p, 'part' => $i + 1, 'total' => $total], $parts, array_keys($parts));
    }

    public function png(array $snapshot, int $part = 1): string
    {
        $parts = $this->pngParts($snapshot);

        if (! $parts) {
            throw new RuntimeException('Tuần này chưa có nhân viên nào trên bảng xếp ca.');
        }

        $p = $parts[max(1, min($part, count($parts))) - 1];
        [$regular, $bold] = $this->fonts();
        $days = $snapshot['days'];
        $draft = ! isset($snapshot['version']);

        // Kích thước (px): đủ lớn để đọc rõ trên điện thoại khi gửi Zalo.
        $pad = 32;
        $rowH = 46;
        $colNo = 76;
        $colName = max(300, min(440, 30 + $this->textWidth($bold, 19, $this->longestText($p['rows'], 'name'))));
        $colPos = max(200, min(340, 30 + $this->textWidth($regular, 16, $this->longestText($p['rows'], 'position'))));
        $colDay = 128;
        $width = $pad * 2 + $colNo + $colName + $colPos + $colDay * count($days);
        $headerTop = 150;
        $height = $headerTop + $rowH * (2 + count($p['rows'])) + 90;

        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 25, 28, 30);
        $muted = imagecolorallocate($img, 95, 105, 120);
        $line = imagecolorallocate($img, 205, 212, 222);
        $head = imagecolorallocate($img, 232, 238, 246);
        $sectionBg = imagecolorallocate($img, 252, 239, 217);
        $offBg = imagecolorallocate($img, 238, 238, 238);
        $red = imagecolorallocate($img, 192, 57, 43);
        $navy = imagecolorallocate($img, 10, 37, 64);
        imagefilledrectangle($img, 0, 0, $width, $height, $white);

        imagettftext($img, 30, 0, $pad, 58, $draft ? $red : $navy, $bold, 'LỊCH LÀM VIỆC TUẦN' . ($draft ? ' – BẢN NHÁP' : ''));
        imagettftext($img, 18, 0, $pad, 94, $ink, $regular, $this->snapshots->period($snapshot) . ' · ' . $snapshot['scope_label']);
        imagettftext($img, 15, 0, $pad, 124, $draft ? $red : $muted, $regular, $this->snapshots->caption($snapshot)
            . ($p['total'] > 1 ? sprintf(' · Phần %d/%d', $p['part'], $p['total']) : ''));

        $x0 = $pad;
        $x1 = $width - $pad;
        $y = $headerTop;

        // Hàng tiêu đề cột.
        imagefilledrectangle($img, $x0, $y, $x1, $y + $rowH, $head);
        $this->cellText($img, $bold, 16, $ink, $x0, $colNo, $y, $rowH, 'STT', true);
        $this->cellText($img, $bold, 16, $ink, $x0 + $colNo, $colName, $y, $rowH, 'Họ và tên');
        $this->cellText($img, $bold, 16, $ink, $x0 + $colNo + $colName, $colPos, $y, $rowH, 'Chức vụ');

        foreach ($days as $k => $day) {
            $this->cellText($img, $bold, 16, $ink, $x0 + $colNo + $colName + $colPos + $colDay * $k, $colDay, $y, $rowH, $day['label'], true);
        }

        $y += $rowH;
        // Hàng tên khu.
        imagefilledrectangle($img, $x0, $y, $x1, $y + $rowH, $sectionBg);
        $this->cellText($img, $bold, 17, $navy, $x0, $x1 - $x0, $y, $rowH, mb_strtoupper($p['section']));
        $y += $rowH;

        foreach ($p['rows'] as $i => $row) {
            foreach ($row['cells'] as $k => $cell) {
                if (! empty($row['offs'][$k])) {
                    $cx = $x0 + $colNo + $colName + $colPos + $colDay * $k;
                    imagefilledrectangle($img, $cx, $y, $cx + $colDay, $y + $rowH, $offBg);
                }
            }

            $this->cellText($img, $regular, 16, $muted, $x0, $colNo, $y, $rowH, (string) ($p['start'] + $i + 1), true);
            $this->cellText($img, $bold, 19, $ink, $x0 + $colNo, $colName, $y, $rowH, $row['name']);
            $this->cellText($img, $regular, 16, $muted, $x0 + $colNo + $colName, $colPos, $y, $rowH, $row['position']);

            foreach ($row['cells'] as $k => $cell) {
                $this->cellText($img, $bold, ! empty($row['offs'][$k]) ? 16 : 18, ! empty($row['offs'][$k]) ? $muted : $ink,
                    $x0 + $colNo + $colName + $colPos + $colDay * $k, $colDay, $y, $rowH, $cell, true);
            }

            $y += $rowH;
        }

        // Lưới.
        $top = $headerTop;
        foreach (range(0, 2 + count($p['rows'])) as $r) {
            imageline($img, $x0, $top + $rowH * $r, $x1, $top + $rowH * $r, $line);
        }
        $xs = [$x0, $x0 + $colNo, $x0 + $colNo + $colName, $x0 + $colNo + $colName + $colPos];
        foreach (range(1, count($days)) as $k) {
            $xs[] = $x0 + $colNo + $colName + $colPos + $colDay * $k;
        }
        foreach ($xs as $x) {
            imageline($img, $x, $top, $x, $top + $rowH, $line);
            imageline($img, $x, $top + $rowH * 2, $x, $y, $line);
        }
        imageline($img, $x0, $top + $rowH, $x0, $top + $rowH * 2, $line);
        imageline($img, $x1, $top + $rowH, $x1, $top + $rowH * 2, $line);

        // Chú thích ca.
        $legend = 'Chú thích: ' . implode(' · ', array_map(fn ($code, $time) => "{$code} {$time}", array_keys($snapshot['legend']), $snapshot['legend'])) . ' · OFF: nghỉ';
        imagettftext($img, 14, 0, $pad, $y + 40, $muted, $regular, $this->fit($regular, 14, $legend, $width - $pad * 2));

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
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
