@php
    // Lobby Bar: số nữ waiter có mặt CÙNG LÚC (ít nhất trong khung chính) – đếm như bộ giải, theo từng đoạn giờ.
    $lobby = $grid['stats']['onDuty']['lobby'] ?? [];
@endphp

@if ($lobby)
    <tr class="roster-stat-row roster-stat-row--first">
        <td class="roster-table__staff">
            <span class="roster-stat-row__label">LOBBY</span>
            <span class="roster-stat-row__hint">cùng lúc ít nhất / tối thiểu</span>
        </td>
        @foreach ($grid['days'] as $day)
            @php
                $check = $lobby[$day->toDateString()] ?? null;
            @endphp
            <td class="roster-stat {{ $check && $check['short'] ? 'is-problem' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}"
                @if ($check)
                    title="{{ $check['short'] ? 'Thiếu: ' . implode('; ', $check['short']) . '. ' : 'Đủ người cùng lúc. ' }}Theo đoạn: {{ implode('; ', $check['segments']) }}. Có mặt: {{ $check['present'] ? implode(', ', $check['present']) : 'chưa có ai' }}"
                @endif>
                @if ($check)
                    <span class="roster-stat__total">{{ $check['min_count'] }}/{{ $check['min'] }}</span>
                    @foreach (array_slice($check['short'], 0, 2) as $short)
                        <span class="roster-stat__roles">{{ \Illuminate\Support\Str::before(\Illuminate\Support\Str::after($short, 'Lobby '), ':') }}</span>
                    @endforeach
                @else
                    <span class="roster-stat__none">—</span>
                @endif
            </td>
        @endforeach
    </tr>
@endif
