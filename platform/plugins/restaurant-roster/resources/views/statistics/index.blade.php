@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $weekdays = ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
    $total = end($headcount);
    $code = fn ($outlet) => $outlet?->code ?: $outlet?->name;
@endphp

@section('content')
    <div class="row row-cards mb-3">
        @foreach ([
            ['Tổng nhân viên', $total['total'], ''],
            ['Nam', $total['male'], ''],
            ['Nữ', $total['female'], ''],
            ['Quản lý (Junior Captain trở lên)', $total['leaders'], ''],
            ['Chưa khai giới tính', $total['no_gender'], $total['no_gender'] ? 'text-danger' : 'text-success'],
            ['Chưa khai nơi ở', $total['no_residence'], $total['no_residence'] ? 'text-warning' : 'text-success'],
        ] as [$label, $value, $class])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card card-sm"><div class="card-body">
                    <div class="text-muted small">{{ $label }}</div>
                    <div class="h2 mb-0 {{ $class }}">{{ $value }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Nhân sự theo outlet</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter table-sm card-table roster-stats">
                <thead>
                    <tr>
                        <th>Outlet</th>
                        <th class="text-center">Tổng</th>
                        <th class="text-center">Nam</th>
                        <th class="text-center">Nữ</th>
                        <th class="text-center" title="Junior Restaurant Captain trở lên">Quản lý</th>
                        <th class="text-center">Nha Trang</th>
                        <th class="text-center">Cam Ranh</th>
                        <th class="text-center">Chưa khai nơi ở</th>
                        <th>Ca cứng</th>
                        <th>OFF / tuần</th>
                        <th class="text-center" title="Không tham gia xếp lịch tự động">Xếp tay</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($headcount as $row)
                        <tr @class(['fw-bold table-light' => ! $row['outlet']])>
                            <td>{{ $row['outlet'] ? $row['outlet']->name : 'Tất cả' }}</td>
                            <td class="text-center">{{ $row['total'] }}</td>
                            <td class="text-center">{{ $row['male'] }}</td>
                            <td class="text-center">{{ $row['female'] }}</td>
                            <td class="text-center">{{ $row['leaders'] }} <span class="text-muted small">({{ $row['leaders_male'] }} nam)</span></td>
                            <td class="text-center">{{ $row['nha_trang'] ?: '—' }}</td>
                            <td class="text-center">{{ $row['cam_ranh'] ?: '—' }}</td>
                            <td @class(['text-center', 'text-warning fw-semibold' => $row['no_residence']])>{{ $row['no_residence'] ?: '—' }}</td>
                            <td>{{ collect($row['fixed'])->map(fn ($n, $c) => "{$c}: {$n}")->implode(' · ') ?: '—' }}</td>
                            <td>{{ collect($row['days_off'])->map(fn ($n, $d) => "{$d} ngày: {$n}")->implode(' · ') }}</td>
                            <td class="text-center">{{ $row['manual'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Vị trí theo outlet</h3><div class="card-subtitle ms-2 text-muted">số người (nam / nữ)</div></div>
        <div class="table-responsive">
            <table class="table table-vcenter table-sm card-table roster-stats">
                <thead>
                    <tr>
                        <th>Vị trí</th>
                        @foreach ($outlets as $outlet)
                            <th class="text-center">{{ $code($outlet) }}</th>
                        @endforeach
                        <th class="text-center">Tổng</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($positions as $position)
                        @continue(! isset($positionMatrix[$position->id]))
                        <tr>
                            <td @class(['fw-semibold' => in_array($position->id, $rules['leaderIds']) || in_array($position->id, $rules['hostessIds'])])>
                                {{ $position->name }}
                                @if (in_array($position->id, $rules['leaderIds']))<span class="badge bg-blue-lt ms-1">Quản lý</span>@endif
                            </td>
                            @foreach ([...$outlets->pluck('id')->all(), 'all'] as $key)
                                @php
                                    $cell = $positionMatrix[$position->id][$key];
                                @endphp
                                <td class="text-center {{ $key === 'all' ? 'fw-bold' : '' }}">
                                    @if ($cell['total'])
                                        {{ $cell['total'] }} <span class="text-muted small">({{ $cell['male'] }} / {{ $cell['female'] }})</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <h3 class="card-title me-auto">
                Đi làm theo ca · tuần {{ $week->format('d/m') }} – {{ $week->copy()->addDays(6)->format('d/m/Y') }}
            </h3>
            <a class="btn btn-sm" href="{{ route('roster.statistics', ['week' => $week->copy()->subWeek()->toDateString()]) }}">← Tuần trước</a>
            <a class="btn btn-sm" href="{{ route('roster.statistics') }}">Tuần này</a>
            <a class="btn btn-sm" href="{{ route('roster.statistics', ['week' => $week->copy()->addWeek()->toDateString()]) }}">Tuần sau →</a>
        </div>
        @if ($onDuty['empty'])
            <div class="card-body text-muted">Tuần này chưa có lịch.</div>
        @else
            <div class="card-body pb-0 small text-muted">
                Mỗi ô: <strong>số người làm tại outlet</strong> (nam / nữ) · QL = quản lý · H = Hostess · +L = người của ca đi hỗ trợ Lobby (không tính vào nhà hàng).
                Ô đỏ: thiếu nam, Hostess hoặc quản lý theo quy định.
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter table-sm card-table roster-stats">
                    <thead>
                        <tr>
                            <th>Ngày</th>
                            @foreach ($onDuty['outlets'] as $outlet)
                                <th colspan="{{ count($onDuty['codes'][$outlet->id] ?? []) + 1 }}" class="text-center border-start">{{ $code($outlet) }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            <th></th>
                            @foreach ($onDuty['outlets'] as $outlet)
                                @foreach ($onDuty['codes'][$outlet->id] ?? [] as $shiftCode)
                                    <th @class(['text-center', 'border-start' => $loop->first])>{{ $shiftCode }}</th>
                                @endforeach
                                <th class="text-center text-muted">OFF / nghỉ</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($onDuty['days'] as $date => $day)
                            <tr>
                                <td class="text-nowrap fw-semibold">{{ $weekdays[$day->dayOfWeekIso] }} {{ $day->format('d/m') }}</td>
                                @foreach ($onDuty['outlets'] as $outlet)
                                    @foreach ($onDuty['codes'][$outlet->id] ?? [] as $shiftCode)
                                        @php
                                            $cell = $onDuty['cells'][$date][$outlet->id][$shiftCode] ?? null;
                                            $checked = isset($rules['checks'][$outlet->code . '|' . $shiftCode]);
                                            $problems = $onDuty['problems'][$date][$outlet->id][$shiftCode] ?? [];
                                        @endphp
                                        <td @class(['text-center text-nowrap', 'border-start' => $loop->first, 'bg-red-lt' => $problems]) title="{{ implode(', ', $problems) }}">
                                            @if ($cell && ($cell['total'] || $cell['lobby']))
                                                <strong>{{ $cell['total'] }}</strong>
                                                <span class="text-muted small">({{ $cell['male'] }}/{{ $cell['female'] }})</span>
                                                @if ($checked)
                                                    <div class="small text-muted">QL {{ $cell['leaders'] }} · H {{ $cell['hostess'] }}</div>
                                                @endif
                                                @if ($cell['lobby'])
                                                    <div class="small text-primary">+{{ $cell['lobby'] }} L</div>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    @php
                                        $absent = $onDuty['absent'][$date][$outlet->id] ?? [];
                                    @endphp
                                    <td class="text-center text-muted small">
                                        {{ $absent['off'] ?? 0 }}@if (! empty($absent['leave'])) · nghỉ {{ $absent['leave'] }}@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
