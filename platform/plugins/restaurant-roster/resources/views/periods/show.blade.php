@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $riskBadges = ['high' => ['Nguy cơ thiếu tối thiểu', 'danger'], 'medium' => ['Dưới mục tiêu', 'warning'], 'ok' => ['Đủ', 'success']];
    $outletIds = collect($summary['days'])->flatMap(fn ($d) => array_keys($d['outlets']))->unique()->filter(fn ($id) => $id !== '' && $id !== null)->values();
    $canApprove = Auth::user()->hasPermission('roster.approve');
@endphp

@section('content')
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="mb-1">{{ $period->name }}</h2>
                <div class="text-muted">{{ $period->start_date->format('d/m/Y') }} – {{ $period->end_date->format('d/m/Y') }} ·
                    {{ $summary['total_off'] }} ngày OFF / nghỉ đã ghi
                    @if ($summary['pending'])
                        · <span class="text-warning">{{ $summary['pending'] }} đơn cũ còn "chờ xử lý"</span>
                    @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('roster.availability.create') }}" class="btn"><x-core::icon name="ti ti-calendar-plus" /> Nhập OFF (từ Zalo)</a>
                <a href="{{ route('roster.availability.index') }}" class="btn">Danh sách OFF</a>
                <a href="{{ route('roster.index', ['week' => $period->start_date->toDateString()]) }}" class="btn">Bảng xếp ca</a>
                @if ($canApprove)
                    <form method="POST" action="{{ route('roster.periods.destroy', $period->id) }}"
                        onsubmit="return confirm('Xóa kỳ lịch {{ $period->name }}? Lịch làm việc và OFF đã ghi không bị xóa.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger"><x-core::icon name="ti ti-trash" /> Xóa kỳ</button>
                    </form>
                @endif
                @if ($canApprove)
                    @if ($period->off_confirmed_at)
                        <form method="POST" action="{{ route('roster.periods.reopen-off', $period->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning">Mở lại nhập OFF</button>
                        </form>
                        <a href="{{ route('roster.auto.index', ['from' => $period->start_date->toDateString(), 'to' => $period->end_date->toDateString()]) }}" class="btn btn-primary">
                            <x-core::icon name="ti ti-wand" /> Tạo lịch tự động
                        </a>
                    @else
                        <form method="POST" action="{{ route('roster.periods.confirm-off', $period->id) }}"
                            onsubmit="return confirm('Xác nhận đã nhập xong OFF cho kỳ này? Sau đó mới chạy được xếp lịch tự động.')">
                            @csrf
                            <button type="submit" class="btn btn-primary"><x-core::icon name="ti ti-check" /> Xác nhận đã nhập xong OFF</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
        @if ($period->off_confirmed_at)
            <div class="card-footer text-success small">
                <x-core::icon name="ti ti-circle-check" /> Đã xác nhận nhập xong OFF lúc {{ $period->off_confirmed_at->format('d/m/Y H:i') }}.
                OFF nhập thêm sau vẫn được ghi & khóa; chạy "Tối ưu lại" để cập nhật phần lịch chưa khóa.
            </div>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Cảnh báo trước khi xếp lịch</h3></div>
                <div class="list-group list-group-flush">
                    @forelse (array_slice($summary['alerts'], 0, 60) as $alert)
                        <div class="list-group-item">
                            <span class="badge bg-{{ $alert['level'] === 'high' ? 'danger' : 'warning' }}-lt me-1">{{ \Carbon\Carbon::parse($alert['date'])->format('d/m') }}</span>
                            {{ $alert['message'] }}
                        </div>
                    @empty
                        <div class="list-group-item text-success"><x-core::icon name="ti ti-circle-check" /> Không phát hiện ngày / ca nào thiếu người đủ năng lực.</div>
                    @endforelse
                    @if (count($summary['alerts']) > 60)
                        <div class="list-group-item text-muted small">… và {{ count($summary['alerts']) - 60 }} cảnh báo khác.</div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Sức chứa theo tuần</h3></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Tuần từ</th><th>Ngày làm tối đa</th><th>Cần (tối thiểu / mục tiêu)</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($summary['weeks'] as $week)
                                @php [$label, $color] = $riskBadges[$week['risk']]; @endphp
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($week['week'])->format('d/m') }} ({{ count($week['days']) }} ngày)</td>
                                    <td class="font-monospace">{{ $week['capacity'] }}</td>
                                    <td class="font-monospace">{{ $week['min'] }} / {{ $week['target'] }}</td>
                                    <td><span class="badge bg-{{ $color }}-lt">{{ $label }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">Ngày làm tối đa = mỗi người tối đa (7 − số ngày OFF/tuần), trừ ngày đã OFF / nghỉ phép.</div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">OFF & người đi làm được theo ngày</h3></div>
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th class="text-center">OFF</th>
                                <th class="text-center">Đi làm được</th>
                                <th class="text-center">Cần</th>
                                @foreach ($outletIds as $outletId)
                                    <th class="text-center" title="OFF · đi làm được / cần tối thiểu">{{ $summary['outlet_names'][$outletId] ?? $outletId }}</th>
                                @endforeach
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary['days'] as $day)
                                @php
                                    $date = \Carbon\Carbon::parse($day['date']);
                                    [$label, $color] = $riskBadges[$day['risk']];
                                @endphp
                                <tr class="{{ $date->isSaturday() ? 'table-active' : '' }}">
                                    <td class="text-nowrap">{{ $dayNames[$date->dayOfWeekIso] }} {{ $date->format('d/m') }}</td>
                                    <td class="text-center font-monospace">{{ $day['off'] }}</td>
                                    <td class="text-center font-monospace">{{ $day['available'] }}</td>
                                    <td class="text-center font-monospace">{{ $day['min'] }}/{{ $day['target'] }}</td>
                                    @foreach ($outletIds as $outletId)
                                        @php $o = $day['outlets'][$outletId] ?? ['off' => 0, 'available' => 0, 'min' => 0, 'target' => 0]; @endphp
                                        <td class="text-center font-monospace small {{ $o['min'] > 0 && $o['available'] < $o['min'] ? 'text-danger fw-bold' : '' }}">
                                            {{ $o['off'] }} · {{ $o['available'] }}@if ($o['min'])/{{ $o['min'] }}@endif
                                        </td>
                                    @endforeach
                                    <td><span class="badge bg-{{ $color }}-lt">{{ $label }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    "Đi làm được" chưa trừ các ngày OFF hằng tuần thuật toán sẽ còn xếp – dùng để nhận biết ngày OFF dồn nhiều, không phải số người sẽ đi làm.
                </div>
            </div>
        </div>
    </div>
@endsection
