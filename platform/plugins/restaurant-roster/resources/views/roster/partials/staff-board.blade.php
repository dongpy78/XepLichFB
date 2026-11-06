@php
    // Thống kê nhân sự cuối bảng xếp ca (RosterStatistics::board): mỗi ô là số người theo từng ca của ngày đó.
    $board = $grid['board'];
    $boardDays = $grid['days'];
    $boardNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $chips = function (array $codes) use ($board): string {
        $html = '';

        foreach ($codes as $code => $count) {
            $html .= '<span class="roster-count roster-chip--' . e($board['colors'][$code] ?? 'slate') . '">' . e($code) . ' <b>' . (int) $count . '</b></span>';
        }

        return $html;
    };
    $absentChips = function (array $absent): string {
        return (! empty($absent['off']) ? '<span class="roster-count roster-chip--off">OFF <b>' . (int) $absent['off'] . '</b></span>' : '')
            . (! empty($absent['leave']) ? '<span class="roster-count roster-chip--leave">Phép <b>' . (int) $absent['leave'] . '</b></span>' : '');
    };
    $none = '<span class="roster-stat__none">—</span>';
@endphp

@if ($board['has_shifts'])
    <tr class="roster-board-title" id="roster-board">
        <td class="roster-table__staff">
            <span class="roster-board-title__name">THỐNG KÊ NHÂN SỰ</span>
            <span class="roster-board-title__hint">Số người đi làm theo ca · ô vàng: lệch M6 / A2 · ô đỏ: cần xem</span>
        </td>
        @foreach ($boardDays as $day)
            <td class="{{ $day->isWeekend() ? 'is-weekend' : '' }}">{{ $boardNames[$day->dayOfWeekIso] }} {{ $day->format('d/m') }}</td>
        @endforeach
    </tr>

    @foreach ($board['restaurants'] as $restaurant)
        <tr class="roster-stat-row roster-board-head">
            <td class="roster-table__staff">
                <span class="roster-stat-row__label">{{ $restaurant['code'] }}</span>
                <span class="roster-stat-row__hint">{{ $restaurant['hint'] }}</span>
            </td>
            @foreach ($boardDays as $day)
                @php $cell = $restaurant['total'][$day->toDateString()]; @endphp
                <td class="roster-stat {{ $cell['problems'] ? 'is-problem' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}"
                    @if ($cell['problems']) title="{{ $restaurant['code'] }}: {{ implode(', ', $cell['problems']) }}" @endif>
                    @if ($cell['closed'])
                        <span class="roster-count roster-count--closed">Đóng cửa</span>
                    @endif
                    @if ($cell['count'])
                        <span class="roster-stat__total">{{ $cell['count'] }}</span> {!! $chips($cell['codes']) !!}
                    @elseif (! $cell['closed'])
                        {!! $none !!}
                    @endif
                    @foreach ($cell['notes'] as $note)
                        <span class="roster-stat__roles">{{ $note }}</span>
                    @endforeach
                </td>
            @endforeach
        </tr>

        @foreach ($restaurant['rows'] as $row)
            <tr class="roster-stat-row">
                <td class="roster-table__staff">
                    <span class="roster-stat-row__role">{{ $row['label'] }}</span>
                    @if (! empty($row['hint']))
                        <span class="roster-stat-row__hint">{{ $row['hint'] }}</span>
                    @endif
                </td>
                @foreach ($boardDays as $day)
                    @php $warn = $row['warn'][$day->toDateString()] ?? null; @endphp
                    <td class="roster-stat {{ $warn ? 'is-warn' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}" @if ($warn) title="{{ $warn }}" @endif>
                        {!! $chips($row['cells'][$day->toDateString()] ?? []) ?: $none !!}
                    </td>
                @endforeach
            </tr>
        @endforeach

        <tr class="roster-stat-row">
            <td class="roster-table__staff">
                <span class="roster-stat-row__role">OFF · phép</span>
                <span class="roster-stat-row__hint">{{ $restaurant['absent_hint'] }}</span>
            </td>
            @foreach ($boardDays as $day)
                <td class="roster-stat {{ $day->isWeekend() ? 'is-weekend' : '' }}">
                    {!! $absentChips($restaurant['absent'][$day->toDateString()] ?? []) ?: $none !!}
                </td>
            @endforeach
        </tr>
    @endforeach

    @if ($board['others'])
        <tr class="roster-stat-row roster-board-head">
            <td class="roster-table__staff">
                <span class="roster-stat-row__label">KHU VỰC KHÁC</span>
            </td>
            @foreach ($boardDays as $day)
                <td class="roster-stat {{ $day->isWeekend() ? 'is-weekend' : '' }}"></td>
            @endforeach
        </tr>

        @foreach ($board['others'] as $row)
            <tr class="roster-stat-row">
                <td class="roster-table__staff">
                    <span class="roster-stat-row__role">{{ $row['label'] }}</span>
                    @if (! empty($row['hint']))
                        <span class="roster-stat-row__hint">{{ $row['hint'] }}</span>
                    @endif
                </td>
                @foreach ($boardDays as $day)
                    @php $cell = $row['cells'][$day->toDateString()]; @endphp
                    <td class="roster-stat {{ $cell['problems'] ? 'is-problem' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}"
                        @if ($cell['problems']) title="{{ $row['label'] }}: {{ implode(', ', $cell['problems']) }}" @endif>
                        {!! ($chips($cell['codes']) . $absentChips($cell['absent'])) ?: $none !!}
                        @foreach ($cell['notes'] as $note)
                            <span class="roster-stat__roles">{{ $note }}</span>
                        @endforeach
                    </td>
                @endforeach
            </tr>
        @endforeach
    @endif

    @include('plugins/restaurant-roster::roster.partials.lobby-stats')

    <tr class="roster-stat-row roster-board-head roster-board-total">
        <td class="roster-table__staff">
            <span class="roster-stat-row__label">TOÀN BỘ</span>
            <span class="roster-stat-row__hint">không tính thực tập</span>
        </td>
        @foreach ($boardDays as $day)
            @php $total = $board['grand'][$day->toDateString()] ?? []; @endphp
            <td class="roster-stat {{ $day->isWeekend() ? 'is-weekend' : '' }}">
                <span class="roster-stat__total">{{ $total['work'] ?? 0 }}</span> đi làm
                {!! $absentChips($total) !!}
            </td>
        @endforeach
    </tr>
@endif
