@php
    $onDuty = $grid['stats']['onDuty'];
    $rules = $grid['stats']['rules'];
    $outletList = $grid['stats']['outlets'];
    // Outlet của section và các khu trực thuộc (vd Kid-Deline dưới Gusto).
    $statOutlets = collect([$outletList->get($outletId)])
        ->merge($grid['stats']['children'][$outletId] ?? [])
        ->filter();
@endphp

@foreach ($statOutlets as $statOutlet)
    @foreach ($onDuty['codes'][$statOutlet->id] ?? [] as $shiftCode)
        @php
            $checked = isset($rules['checks'][$statOutlet->code . '|' . $shiftCode]);
        @endphp
        <tr class="roster-stat-row {{ $loop->parent->first && $loop->first ? 'roster-stat-row--first' : '' }}">
            <td class="roster-table__staff">
                <span class="roster-stat-row__label">
                    @if ($statOutlet->id !== $outletId){{ $statOutlet->code }} · @endif{{ $shiftCode }}
                </span>
                <span class="roster-stat-row__hint">người (nam/nữ){{ $checked ? ' · QL · H' : '' }}</span>
            </td>
            @foreach ($grid['days'] as $day)
                @php
                    $key = $day->toDateString();
                    $cell = $onDuty['cells'][$key][$statOutlet->id][$shiftCode] ?? null;
                    $problems = $onDuty['problems'][$key][$statOutlet->id][$shiftCode] ?? [];
                @endphp
                <td class="roster-stat {{ $problems ? 'is-problem' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}"
                    @if ($problems) title="{{ $statOutlet->code }} {{ $shiftCode }}: {{ implode(', ', $problems) }}" @endif>
                    @if ($cell && ($cell['total'] || $cell['lobby']))
                        @if ($cell['total'] || $checked)
                            <span class="roster-stat__total">{{ $cell['total'] }}</span>
                            <span class="roster-stat__split">{{ $cell['male'] }}/{{ $cell['female'] }}</span>
                        @endif
                        @if ($checked)
                            <span class="roster-stat__roles">QL{{ $cell['leaders'] }} · H{{ $cell['hostess'] }}</span>
                        @endif
                        @if ($cell['lobby'])
                            <span class="roster-stat__lobby">+{{ $cell['lobby'] }}L</span>
                        @endif
                    @else
                        <span class="roster-stat__none">—</span>
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
@endforeach

<tr class="roster-stat-row roster-stat-row--off">
    <td class="roster-table__staff">
        <span class="roster-stat-row__label">OFF</span>
        <span class="roster-stat-row__hint">OFF · nghỉ phép</span>
    </td>
    @foreach ($grid['days'] as $day)
        @php
            $absent = $onDuty['absent'][$day->toDateString()][$outletId] ?? [];
        @endphp
        <td class="roster-stat {{ $day->isWeekend() ? 'is-weekend' : '' }}">
            <span class="roster-stat__total">{{ $absent['off'] ?? 0 }}</span>
            @if (! empty($absent['leave']))
                <span class="roster-stat__split">nghỉ {{ $absent['leave'] }}</span>
            @endif
        </td>
    @endforeach
</tr>
