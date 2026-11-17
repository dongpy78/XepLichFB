@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $total = end($headcount);
    $code = fn ($outlet) => $outlet?->code ?: $outlet?->name;
    $weekEnd = $week->copy()->addDays(6);
    $isThisWeek = $week->isSameDay(\Carbon\Carbon::today()->startOfWeek(\Carbon\Carbon::MONDAY));
    $issues = array_filter([
        $total['no_gender'] ? $total['no_gender'] . ' người chưa khai giới tính' : null,
        $total['no_residence'] ? $total['no_residence'] . ' người chưa khai khu vực sinh sống' : null,
    ]);
@endphp

@section('content')
    <div class="roster-stats-page">
        {{-- Thanh tuần --}}
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto">
                    <div class="text-muted small text-uppercase fw-semibold">Thống kê nhân sự</div>
                    <div class="h3 mb-0">Tuần {{ $week->isoWeek() }} · {{ $week->format('d/m') }} – {{ $weekEnd->format('d/m/Y') }}
                        @if ($isThisWeek)<span class="badge bg-primary-lt ms-1">Tuần này</span>@endif
                    </div>
                </div>
                <div class="btn-group">
                    <a class="btn" href="{{ route('roster.statistics', ['week' => $week->copy()->subWeek()->toDateString()]) }}"><x-core::icon name="ti ti-chevron-left" /> Tuần trước</a>
                    <a class="btn" href="{{ route('roster.statistics') }}">Tuần này</a>
                    <a class="btn" href="{{ route('roster.statistics', ['week' => $week->copy()->addWeek()->toDateString()]) }}">Tuần sau <x-core::icon name="ti ti-chevron-right" /></a>
                </div>
                <a class="btn btn-primary" href="{{ route('roster.index', ['week' => $week->toDateString()]) }}"><x-core::icon name="ti ti-layout-grid" /> Mở Bảng xếp ca</a>
            </div>
        </div>

        {{-- Tổng quan: 4 nhóm xếp lịch --}}
        <div class="row row-cards mb-3">
            @foreach ([
                ['Tổng nhân viên', $total['total'], $total['male'] . ' nam · ' . $total['female'] . ' nữ', 'ti ti-users'],
                ['Incharge', $groups['incharge'], 'Junior Captain → Senior Supervisor', 'ti ti-star'],
                ['Hostess', $groups['hostess'], 'chia đều M6 / A2', 'ti ti-user-heart'],
                ['Waiter nữ', $groups['waiter_female'], 'rải OFF riêng', 'ti ti-user'],
                ['Waiter nam', $groups['waiter_male'], 'rải OFF riêng', 'ti ti-user'],
                ['Quản lý · Thực tập', $groups['managers'] . ' · ' . $groups['trainee'], 'không tính rải OFF', 'ti ti-briefcase'],
            ] as [$label, $value, $hint, $icon])
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card card-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 text-muted small"><x-core::icon :name="$icon" /> {{ $label }}</div>
                            <div class="h1 mb-0 mt-1">{{ $value }}</div>
                            <div class="text-muted small">{{ $hint }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($issues)
            <div class="alert alert-warning py-2"><x-core::icon name="ti ti-alert-triangle" /> Dữ liệu hồ sơ cần bổ sung: {{ implode(' · ', $issues) }}.</div>
        @endif

        {{-- Theo ngày: đúng khối thống kê cuối Bảng xếp ca --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Nhân sự đi làm theo ngày</h3>
                <div class="card-subtitle ms-2 text-muted">số người theo ca · ô vàng: lệch M6 / A2 · ô đỏ: cần xem</div>
            </div>
            @if (! $grid['board']['has_shifts'])
                <div class="card-body text-muted">Tuần này chưa có lịch.</div>
            @else
                <div class="table-responsive roster-scroll">
                    <table class="roster-table roster-table--compact roster-stats-board">
                        <tbody>
                            @include('plugins/restaurant-roster::roster.partials.staff-board')
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="row row-cards">
            {{-- Cơ cấu theo outlet --}}
            <div class="col-xl-7">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Cơ cấu nhân sự theo outlet</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter table-sm card-table">
                            <thead>
                                <tr>
                                    <th>Outlet</th>
                                    <th class="text-end">Tổng</th>
                                    <th class="text-end">Nam</th>
                                    <th class="text-end">Nữ</th>
                                    <th class="text-end" title="Cấp quản lý (gồm Assistant / Restaurant Manager)">Quản lý</th>
                                    <th class="text-end">Nha Trang</th>
                                    <th class="text-end">Cam Ranh</th>
                                    <th>OFF / tuần</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($headcount as $row)
                                    <tr @class(['fw-bold bg-body-tertiary' => ! $row['outlet']])>
                                        <td>{{ $row['outlet'] ? $code($row['outlet']) : 'Tất cả' }}</td>
                                        <td class="text-end">{{ $row['total'] }}</td>
                                        <td class="text-end">{{ $row['male'] }}</td>
                                        <td class="text-end">{{ $row['female'] }}</td>
                                        <td class="text-end">{{ $row['leaders'] ?: '—' }}</td>
                                        <td class="text-end">{{ $row['nha_trang'] ?: '—' }}</td>
                                        <td class="text-end">{{ $row['cam_ranh'] ?: '—' }}</td>
                                        <td class="text-muted small">{{ collect($row['days_off'])->map(fn ($n, $d) => "{$d} ngày: {$n}")->implode(' · ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Vị trí × outlet --}}
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Vị trí theo outlet</h3><div class="card-subtitle ms-2 text-muted">nam / nữ</div></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter table-sm card-table">
                            <thead>
                                <tr>
                                    <th>Vị trí</th>
                                    @foreach ($outlets as $outlet)
                                        @continue(! collect($positionMatrix)->contains(fn ($row) => ($row[$outlet->id]['total'] ?? 0) > 0))
                                        <th class="text-end">{{ $code($outlet) }}</th>
                                    @endforeach
                                    <th class="text-end">Tổng</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($positions as $position)
                                    @continue(! isset($positionMatrix[$position->id]))
                                    <tr>
                                        <td>
                                            {{ $position->name }}
                                            @if (in_array($position->id, $rules['inChargeIds'] ?? []))
                                                <span class="badge bg-blue-lt ms-1">Incharge</span>
                                            @elseif (in_array($position->id, $rules['leaderIds']))
                                                <span class="badge bg-secondary-lt ms-1">Quản lý</span>
                                            @endif
                                        </td>
                                        @foreach ($outlets as $outlet)
                                            @continue(! collect($positionMatrix)->contains(fn ($row) => ($row[$outlet->id]['total'] ?? 0) > 0))
                                            @php($cell = $positionMatrix[$position->id][$outlet->id])
                                            <td class="text-end">
                                                @if ($cell['total'])
                                                    {{ $cell['total'] }} <span class="text-muted small">{{ $cell['male'] }}/{{ $cell['female'] }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end fw-semibold">{{ $positionMatrix[$position->id]['all']['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
