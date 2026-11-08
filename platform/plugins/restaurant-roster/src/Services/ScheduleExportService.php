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
     * Ảnh PNG một tấm cho cả tuần (quản lý 30/10/2026): cùng font (Inter) và bảng màu với Bảng xếp ca – màu ca theo Mẫu ca
     * (sky, sand, amber, violet…), OFF đỏ nhạt, nghỉ phép hồng. Bảng giãn đủ chiều rộng ảnh; tiêu đề chỉ "LỊCH LÀM VIỆC TUẦN"
     * và ngày áp dụng; ngày sinh nhân viên sau tên. Bản nháp có nhãn BẢN NHÁP đỏ.
     */
    public function png(array $snapshot): string
    {
        $sections = array_values(array_filter($snapshot['sections'], fn ($s) => $s['rows']));

        if (! $sections) {
            throw new RuntimeException('Tuần này chưa có nhân viên nào trên bảng xếp ca.');
        }

        $f = $this->fonts();
        $days = $snapshot['days'];
        $draft = ! isset($snapshot['version']);
        $rows = array_merge(...array_map(fn ($s) => $s['rows'], $sections));

        // Sinh nhật rơi vào tháng của tuần lịch → tô nổi bật như Bảng xếp ca.
        // Như Bảng xếp ca, lấy ngày đầu tuần làm "hôm nay": sinh nhật từ đầu tuần đến hết tháng đó (hoặc hết tuần nếu tuần sang tháng
        // mới) thì tô nổi; đã qua rồi hoặc ở tháng sau thì không. Vd tuần 26/10 – 01/11: 26/10 … 01/11 tô, 03/11 không.
        $from = $days[0]['date'];
        $to = max(end($days)['date'], date('Y-m-t', strtotime($from)));
        $birthdayThisMonth = function (array $r) use ($from, $to): bool {
            if (empty($r['birthday']) || ! preg_match('/^(\d{2})\/(\d{2})$/', $r['birthday'], $m)) {
                return false;
            }

            foreach (array_unique([substr($from, 0, 4), substr($to, 0, 4)]) as $year) {
                $date = "{$year}-{$m[2]}-{$m[1]}";

                if ($date >= $from && $date <= $to) {
                    return true;
                }
            }

            return false;
        };
        $pad = 20;
        $headerH = 164;
        $headH = 68;
        $sectionH = 46;
        $footerH = 110;
        // Xem trên điện thoại: ảnh hẹp hơn màn hình thì hai bên dư đen. Giữ rộng / cao từ 1 : 2 (màn hình điện thoại 9 : 18 trở lên phủ kín chiều ngang):
        // trước hết giảm chiều cao hàng (66 → 62, giữ khoảng thoáng dễ đọc), đông người quá nữa thì rộng ảnh thêm (cột ngày rộng ra).
        $width = 1800;
        $fixedH = $headerH + $headH + $sectionH * count($sections) + $footerH;
        $rowH = max(62, min(66, (int) floor(($width * 2 - $fixedH) / count($rows))));
        $width = max($width, min(2800, (int) ceil(($fixedH + $rowH * count($rows)) / 2)));
        $colNo = 56;
        $nameNeed = max(array_map(fn ($r) => $this->textWidth($f['semibold'], 19, $r['name'])
            + (! empty($r['birthday']) ? $this->textWidth($f['bold'], 16, '(' . $r['birthday'] . ')') + 30 : 0), $rows));
        $colName = max(360, min(560, $nameNeed + 36));
        $colDay = (int) floor(($width - $pad * 2 - $colNo - $colName) / max(1, count($days)));
        $colName = $width - $pad * 2 - $colNo - $colDay * count($days);
        $tableTop = $headerH;
        $tableH = $headH + $sectionH * count($sections) + $rowH * count($rows);
        $height = $tableTop + $tableH + $footerH;

        // Vẽ ở độ phân giải cao (quản lý 02/11/2026: ảnh gửi nhân viên bị mờ khi phóng to trên điện thoại): tọa độ logic vẫn rộng 1800,
        // ảnh thật gấp đôi. Thiếu bộ nhớ thì tự hạ tỉ lệ thay vì lỗi.
        $this->scale = $this->affordableScale($width, $height);
        $img = imagecreatetruecolor((int) ceil($width * $this->scale), (int) ceil($height * $this->scale));
        imagesetthickness($img, (int) max(1, round($this->scale)));
        $c = fn (string $hex) => imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $white = $c('FFFFFF');
        $text = $c('0F172A');
        $muted = $c('475569');
        $faint = $c('94A3B8');
        $line = $c('E5E9F0');
        $headBg = $c('F8FAFC');
        $sectionBg = $c('F1F5F9');
        $weekend = $c('FBFCFD');
        $navy = $c('0A2540');
        $gold = $c('E0A96D');
        $this->drawRect($img, 0, 0, $width, $height, $white);

        // Tiêu đề: "LỊCH LÀM VIỆC TUẦN" + ngày áp dụng (vạch vàng bên trái như thương hiệu).
        $this->drawRect($img, $pad, 40, $pad + 6, 124, $gold);
        $this->drawText($img, 36, 0, $pad + 24, 82, $navy, $f['bold'], 'LỊCH LÀM VIỆC TUẦN');
        $this->drawText($img, 20, 0, $pad + 26, 122, $muted, $f['medium'], 'Ngày ' . $this->snapshots->period($snapshot));

        if ($draft) {
            $tagW = $this->textWidth($f['bold'], 17, 'BẢN NHÁP') + 40;
            $this->roundRect($img, $width - $pad - $tagW, 52, $width - $pad, 94, 8, $c('FEE2E2'));
            $this->drawText($img, 17, 0, $width - $pad - $tagW + 20, 80, $c('DC2626'), $f['bold'], 'BẢN NHÁP');
        }

        $x0 = $pad;
        $x1 = $width - $pad;
        $dayX = fn (int $k) => $x0 + $colNo + $colName + $colDay * $k;
        $isWeekend = fn (array $day) => in_array(explode(' ', $day['label'])[0], ['T7', 'CN'], true);

        // Thẻ bảng: viền + bo góc.
        $this->roundRect($img, $x0 - 1, $tableTop - 1, $x1 + 1, $tableTop + $tableH + 1, 12, $line);
        $this->roundRect($img, $x0, $tableTop, $x1, $tableTop + $tableH, 12, $white);

        // Nền cột cuối tuần (nhạt như bảng xếp ca).
        foreach ($days as $k => $day) {
            if ($isWeekend($day)) {
                $this->drawRect($img, $dayX($k), $tableTop + $headH, $dayX($k) + $colDay - ($k === count($days) - 1 ? 1 : 0), $tableTop + $tableH - 12, $weekend);
            }
        }

        // Hàng tiêu đề.
        $y = $tableTop;
        $this->roundRect($img, $x0, $y, $x1, $y + $headH, 12, $headBg);
        $this->drawRect($img, $x0, $y + 12, $x1, $y + $headH, $headBg);
        $this->cellText($img, $f['semibold'], 14, $faint, $x0, $colNo, $y, $headH, '#', true);
        $this->cellText($img, $f['semibold'], 14, $muted, $x0 + $colNo, $colName, $y, $headH, 'NHÂN VIÊN');

        foreach ($days as $k => $day) {
            [$dow, $date] = array_pad(explode(' ', $day['label'], 2), 2, '');
            $this->cellText($img, $f['semibold'], 18, $text, $dayX($k), $colDay, $y - 11, $headH, $this->dayName($dow), true);
            $this->cellText($img, $f['regular'], 14, $muted, $dayX($k), $colDay, $y + 14, $headH, $date, true);
        }

        $this->drawLine($img, $x0, $y + $headH, $x1, $y + $headH, $line);
        $y += $headH;
        $n = 0;

        foreach ($sections as $section) {
            $this->drawRect($img, $x0, $y, $x1, $y + $sectionH, $sectionBg);
            $label = mb_strtoupper($section['label']);
            $this->drawText($img, 15, 0, $x0 + 18, (int) ($y + $sectionH / 2 + 6), $c('334155'), $f['bold'], $label);
            $this->drawText($img, 14, 0, $x0 + 30 + $this->textWidth($f['bold'], 15, $label), (int) ($y + $sectionH / 2 + 6), $faint, $f['medium'], count($section['rows']) . ' người');
            $this->drawLine($img, $x0, $y + $sectionH, $x1, $y + $sectionH, $line);
            $y += $sectionH;

            foreach ($section['rows'] as $row) {
                $this->cellText($img, $f['medium'], 15, $faint, $x0, $colNo, $y, $rowH, (string) ++$n, true);

                // Tên + ngày sinh "(13/12)" như Bảng xếp ca: chữ xám nhạt; sinh nhật trong tháng → nhãn đỏ nổi bật. Chức vụ bên dưới.
                $nx = $x0 + $colNo + 8;
                $name = $this->fit($f['semibold'], 19, $row['name'], $colName - 24 - (! empty($row['birthday']) ? 100 : 0));
                $nameY = $y + (int) floor($rowH / 2) - 2;
                $this->drawText($img, 19, 0, $nx, $nameY, $text, $f['semibold'], $name);

                if (! empty($row['birthday'])) {
                    $label = '(' . $row['birthday'] . ')';
                    $bx = $nx + $this->textWidth($f['semibold'], 19, $name) + 8;

                    if ($birthdayThisMonth($row)) {
                        $bw = $this->textWidth($f['bold'], 16, $label) + 18;
                        $this->roundRect($img, $bx, $nameY - 20, $bx + $bw, $nameY + 6, 13, $c('FEE2E2'));
                        $this->drawText($img, 16, 0, $bx + 9, $nameY - 1, $c('DC2626'), $f['bold'], $label);
                    } else {
                        $this->drawText($img, 16, 0, $bx, $nameY - 1, $faint, $f['regular'], $label);
                    }
                }

                $this->drawText($img, 14, 0, $nx, $nameY + 21, $muted, $f['regular'], $this->fit($f['regular'], 14, $row['position'], $colName - 24));

                foreach ($row['cells'] as $k => $cell) {
                    if ($cell === '') {
                        continue;
                    }

                    [$fill, $fg] = $this->shiftColors($snapshot, $cell, ! empty($row['offs'][$k]));
                    $cellGap = max(10, $rowH - 52);
                    $this->roundRect($img, $dayX($k) + 10, $y + $cellGap, $dayX($k) + $colDay - 10, $y + $rowH - $cellGap, 8, $c($fill));
                    $this->cellText($img, $f['semibold'], mb_strlen($cell) > 6 ? 14 : 17, $c($fg), $dayX($k), $colDay, $y, $rowH, $cell, true);
                }

                $y += $rowH;
                $this->drawLine($img, $x0, $y, $x1, $y, $line);
            }
        }

        // Kẻ dọc giữa các cột.
        foreach (range(0, count($days) - 1) as $k) {
            $this->drawLine($img, $dayX($k), $tableTop + $headH, $dayX($k), $tableTop + $tableH - 1, $line);
        }

        // Chú thích màu ca.
        $lx = $pad;
        $ly = $tableTop + $tableH + 34;
        $this->drawText($img, 15, 0, $lx, $ly + 21, $muted, $f['semibold'], 'Chú thích');
        $lx += $this->textWidth($f['semibold'], 15, 'Chú thích') + 22;

        foreach ([...$snapshot['legend'], 'OFF' => 'nghỉ'] as $code => $time) {
            [$fill, $fg] = $this->shiftColors($snapshot, (string) $code, $code === 'OFF');
            $chipW = $this->textWidth($f['semibold'], 15, (string) $code) + 26;
            $tw = $this->textWidth($f['regular'], 14, (string) $time);

            if ($lx + $chipW + $tw + 30 > $width - $pad) {
                break;
            }

            $this->roundRect($img, $lx, $ly, $lx + $chipW, $ly + 30, 7, $c($fill));
            $this->drawText($img, 15, 0, $lx + 13, $ly + 21, $c($fg), $f['semibold'], (string) $code);
            $this->drawText($img, 14, 0, $lx + $chipW + 9, $ly + 21, $muted, $f['regular'], (string) $time);
            $lx += $chipW + $tw + 30;
        }

        $this->drawText($img, 13, 0, $pad, $ly + 64, $faint, $f['regular'], 'Selectum Noa · F&B · Xếp lịch nhà hàng');

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function dayName(string $short): string
    {
        return ['T2' => 'Thứ 2', 'T3' => 'Thứ 3', 'T4' => 'Thứ 4', 'T5' => 'Thứ 5', 'T6' => 'Thứ 6', 'T7' => 'Thứ 7', 'CN' => 'Chủ nhật'][$short] ?? $short;
    }

    /**
     * Màu ô ca giống Bảng xếp ca: màu của Mẫu ca (sky / sand / amber / navy / violet / emerald / slate); OFF đỏ nhạt; nghỉ phép hồng.
     * Bản chụp cũ chưa có màu: suy theo giờ bắt đầu.
     *
     * @return array{0: string, 1: string} [nền, chữ] (hex)
     */
    private function shiftColors(array $snapshot, string $cell, bool $off): array
    {
        if ($off) {
            return $cell === 'OFF' ? ['FEE2E2', 'DC2626'] : ['FCE7F3', 'BE185D'];
        }

        $palette = [
            'sky' => ['E0F2FE', '0369A1'], 'sand' => ['FFEDD5', 'C2410C'], 'amber' => ['FEF3C7', 'B45309'], 'navy' => ['334155', 'FFFFFF'],
            'violet' => ['EDE9FE', '6D28D9'], 'emerald' => ['DCFCE7', '15803D'], 'slate' => ['F1F5F9', '475569'],
        ];
        $code = (string) preg_replace('/[(+\s].*$/u', '', $cell);
        $color = $snapshot['colors'][$code] ?? null;

        if ($color === null && isset($snapshot['legend'][$code])) {
            $hour = (int) substr((string) $snapshot['legend'][$code], 0, 2);
            $color = match (true) {
                $hour >= 19 || $hour < 4 => 'navy',
                $hour < 12 => 'sky',
                default => 'sand',
            };
        }

        return $palette[$color] ?? $palette['slate'];
    }

    private function roundRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        [$x1, $y1, $x2, $y2, $r] = array_map(fn ($v) => (int) round($v * $this->scale), [$x1, $y1, $x2, $y2, $r]);
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

    /**
     * Font: Inter (giống trang quản trị / Bảng xếp ca, có tiếng Việt – kèm trong plugin, giấy phép OFL);
     * dự phòng DejaVu Sans của dompdf.
     *
     * @return array{regular: string, medium: string, semibold: string, bold: string}
     */
    private function fonts(): array
    {
        $inter = base_path('platform/plugins/restaurant-roster/resources/fonts');

        if (is_file("{$inter}/Inter-Regular.otf") && is_file("{$inter}/Inter-Bold.otf")) {
            return ['regular' => "{$inter}/Inter-Regular.otf", 'medium' => "{$inter}/Inter-Medium.otf", 'semibold' => "{$inter}/Inter-SemiBold.otf", 'bold' => "{$inter}/Inter-Bold.otf"];
        }

        $dejavu = base_path('vendor/dompdf/dompdf/lib/fonts');

        if (is_file("{$dejavu}/DejaVuSans.ttf")) {
            return ['regular' => "{$dejavu}/DejaVuSans.ttf", 'medium' => "{$dejavu}/DejaVuSans.ttf", 'semibold' => "{$dejavu}/DejaVuSans-Bold.ttf", 'bold' => "{$dejavu}/DejaVuSans-Bold.ttf"];
        }

        throw new RuntimeException('Không tìm thấy font để tạo ảnh PNG.');
    }

    /** Tỉ lệ vẽ ảnh PNG: số điểm ảnh thật cho mỗi đơn vị tọa độ logic. */
    private float $scale = 1.0;

    /** Tỉ lệ cao nhất mà bộ nhớ PHP còn đủ (đo thực tế: 3600 x 10016 điểm ảnh dùng ~306 MB, tức ~8,5 byte / điểm ảnh kể cả khi nén PNG). */
    private function affordableScale(int $width, int $height): float
    {
        @ini_set('memory_limit', '512M');
        $limit = $this->memoryLimitBytes();

        foreach ([2.0, 1.5] as $scale) {
            if ($limit < 0 || memory_get_usage(true) + $width * $height * $scale * $scale * 8.5 < $limit) {
                return $scale;
            }
        }

        return 1.0;
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function drawText($img, float $size, int $angle, int|float $x, int|float $y, int $color, string $font, string $text): void
    {
        imagettftext($img, $size * $this->scale, $angle, (int) round($x * $this->scale), (int) round($y * $this->scale), $color, $font, $text);
    }

    private function drawRect($img, int|float $x1, int|float $y1, int|float $x2, int|float $y2, int $color): void
    {
        imagefilledrectangle($img, (int) round($x1 * $this->scale), (int) round($y1 * $this->scale), (int) round($x2 * $this->scale), (int) round($y2 * $this->scale), $color);
    }

    private function drawLine($img, int|float $x1, int|float $y1, int|float $x2, int|float $y2, int $color): void
    {
        imageline($img, (int) round($x1 * $this->scale), (int) round($y1 * $this->scale), (int) round($x2 * $this->scale), (int) round($y2 * $this->scale), $color);
    }

    private function cellText($img, string $font, int $size, int $color, int $x, int $w, int $y, int $h, string $text, bool $center = false): void
    {
        $text = $this->fit($font, $size, $text, $w - 16);
        $tw = $this->textWidth($font, $size, $text);
        $tx = $center ? (int) ($x + ($w - $tw) / 2) : $x + 10;
        $this->drawText($img, $size, 0, $tx, $y + $h / 2 + $size * 0.38, $color, $font, $text);
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size * $this->scale, 0, $font, $text);

        return (int) ceil(abs($box[2] - $box[0]) / $this->scale);
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
