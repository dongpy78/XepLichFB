<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Lịch làm việc tuần {{ $period }}</title>
    <style>
        @page { margin: 14mm 10mm 12mm 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #191c1e; }
        h1 { font-size: 16pt; margin: 0 0 2pt; color: {{ $draft ? '#c0392b' : '#0a2540' }}; }
        .meta { font-size: 9.5pt; margin: 0 0 1pt; }
        .caption { font-size: 8.5pt; color: {{ $draft ? '#c0392b' : '#5f6978' }}; margin: 0 0 8pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 0.6pt solid #b9c2cf; padding: 3pt 4pt; overflow: hidden; }
        th { background: #e8eef6; font-weight: bold; text-align: center; }
        td.c { text-align: center; font-weight: bold; }
        td.off { background: #eeeeee; color: #5f6978; font-weight: normal; }
        td.name { font-weight: bold; }
        td.pos { color: #5f6978; font-size: 8.5pt; }
        tr.section td { background: #fcefd9; font-weight: bold; color: #0a2540; }
        .legend { margin-top: 8pt; font-size: 8pt; color: #5f6978; }
    </style>
</head>
<body>
    <h1>LỊCH LÀM VIỆC TUẦN{{ $draft ? ' – BẢN NHÁP' : '' }}</h1>
    <p class="meta">{{ $period }} · {{ $snapshot['scope_label'] }}</p>
    <p class="caption">{{ $caption }}</p>

    <table>
        <thead>
            <tr>
                {{-- dompdf: độ rộng cột đặt trên ô tiêu đề (colgroup không được áp dụng). --}}
                <th style="width: 4%">STT</th>
                <th style="width: 23%">Họ và tên</th>
                <th style="width: 13%">Chức vụ</th>
                @foreach ($snapshot['days'] as $day)
                    <th style="width: {{ round(60 / max(1, count($snapshot['days'])), 2) }}%">{{ $day['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php
                $n = 0;
            @endphp
            @foreach ($snapshot['sections'] as $section)
                <tr class="section"><td colspan="{{ 3 + count($snapshot['days']) }}">{{ mb_strtoupper($section['label']) }}</td></tr>
                @foreach ($section['rows'] as $row)
                    <tr>
                        <td class="c">{{ ++$n }}</td>
                        <td class="name">{{ $row['name'] }}</td>
                        <td class="pos">{{ $row['position'] }}</td>
                        @foreach ($row['cells'] as $k => $cell)
                            <td class="c {{ ! empty($row['offs'][$k]) ? 'off' : '' }}">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <p class="legend">Chú thích: @foreach ($snapshot['legend'] as $code => $time){{ $code }} {{ $time }} · @endforeach OFF: nghỉ</p>
</body>
</html>
