@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    use Botble\RestaurantRoster\Models\NightRotation;

    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $statusBadges = ['proposed' => 'warning', 'approved' => 'success', 'published' => 'dark'];
    $locked = $saved->contains('status', NightRotation::STATUS_PUBLISHED);
    $selectedIds = old('employee_ids', $saved->isNotEmpty()
        ? $saved->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        : collect($proposal['selected'])->map(fn ($row) => $row['employee']->getKey())->all());
    $excludedById = collect($proposal['excluded'])->keyBy(fn ($row) => $row['employee']->getKey());
    $scoreById = collect([...$proposal['selected'], ...$proposal['candidates']])->keyBy(fn ($row) => $row['employee']->getKey());
    $person = fn ($e) => $e->name . ' · ' . ($e->outlet?->code ?: '—') . ($e->scheduleGroup && $e->scheduleGroup->code !== $e->outlet?->code ? ' (nhóm ' . $e->scheduleGroup->name . ')' : '');
@endphp

@section('content')
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end justify-content-between">
            <form method="GET" action="{{ route('roster.night.index') }}" class="d-flex gap-2 align-items-end">
                <div>
                    <label class="form-label">Tuần (chọn một ngày bất kỳ)</label>
                    <input type="date" name="week" value="{{ $week->toDateString() }}" class="form-control">
                </div>
                <button class="btn btn-primary" type="submit">Xem</button>
                <a class="btn btn-outline-secondary" href="{{ route('roster.night.index', ['week' => $week->subWeek()->toDateString()]) }}">‹ Tuần trước</a>
                <a class="btn btn-outline-secondary" href="{{ route('roster.night.index', ['week' => $week->addWeek()->toDateString()]) }}">Tuần sau ›</a>
            </form>
            <div class="text-muted small">
                Tuần <strong>{{ $week->format('d/m') }} – {{ $week->addDays(6)->format('d/m/Y') }}</strong> ·
                mỗi tuần chọn <strong>{{ $perWeek }}</strong> nam waiter luân phiên ·
                Night cố định = ca cứng ca đêm · Night đa năng = "Vai trò Night" trong hồ sơ
            </div>
        </div>
    </div>

    <div class="row row-cards mb-3">
        @foreach (['core' => ['Night cố định', 'Người có ca cứng là ca đêm – chọn ở mục Ca cứng của nhân viên'], 'flex' => ['Night đa năng', 'Chọn "Vai trò Night" ở hồ sơ nhân viên – Night / Room Service / hỗ trợ nhà hàng theo năng lực đã khai']] as $key => [$title, $hint])
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">{{ $title }} ({{ $groups[$key]->count() }})</h3></div>
                    <div class="card-body small">
                        <div class="text-muted mb-2">{{ $hint }}</div>
                        @forelse ($groups[$key] as $e)
                            <div>{{ $person($e) }}{{ $e->fixedShift?->code ? ' · ca cứng ' . $e->fixedShift->code : '' }}</div>
                        @empty
                            <div class="text-warning">Chưa có ai{{ $key === 'core' ? ' – chọn Ca cứng N cho nhân viên Night cố định' : ' – đặt "Vai trò Night" trong hồ sơ nhân viên' }}.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Luân phiên Night tuần {{ $week->format('d/m') }}</h3>
            <span class="ms-2 badge bg-{{ $saved->isEmpty() ? 'warning' : $statusBadges[$saved->first()->status] }} text-white">
                {{ $saved->isEmpty() ? 'Chưa duyệt – đang hiện đề xuất' : NightRotation::STATUSES[$saved->first()->status] }}
            </span>
        </div>
        <div class="card-body">
            @foreach ($proposal['warnings'] as $warning)
                <div class="alert alert-warning py-2">{{ $warning }}</div>
            @endforeach
            @if ($locked)
                <div class="alert alert-secondary py-2">Tuần đã công bố (khóa): không sửa danh sách luân phiên.</div>
            @endif

            <form method="POST" action="{{ route('roster.night.save') }}">
                @csrf
                <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead>
                            <tr>
                                <th style="width: 40px"></th>
                                <th>Nam waiter đủ điều kiện</th>
                                <th>Tuần Night ({{ \Botble\RestaurantRoster\Services\NightStaffService::HISTORY_WEEKS }} tuần qua)</th>
                                <th>Đêm làm được</th>
                                <th>Đánh giá</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($groups['rotation'] as $e)
                                @php
                                    $id = $e->getKey();
                                    $h = $history[$id] ?? ['weeks' => 0, 'last_week' => false, 'list' => []];
                                    $ex = $excludedById[$id] ?? null;
                                    $row = $scoreById[$id] ?? $ex;
                                    $isProposed = collect($proposal['selected'])->contains(fn ($r) => $r['employee']->getKey() === $id);
                                @endphp
                                <tr class="{{ $ex ? 'text-muted' : '' }}">
                                    <td><input type="checkbox" class="form-check-input" name="employee_ids[]" value="{{ $id }}" @checked(in_array($id, $selectedIds, true)) @disabled($locked || ! $canEdit)></td>
                                    <td>{{ $person($e) }}</td>
                                    <td>
                                        {{ $h['weeks'] }}
                                        @if ($h['last_week'])<span class="badge bg-warning text-white ms-1">tuần trước</span>@endif
                                        @if ($h['list'])<span class="text-muted small ms-1">({{ implode(', ', $h['list']) }})</span>@endif
                                    </td>
                                    <td>{{ $row['nights'] ?? '—' }}</td>
                                    <td class="small">
                                        @if ($isProposed)
                                            <span class="badge bg-success text-white">Đề xuất</span>
                                        @elseif ($ex)
                                            <span class="text-danger">Bị loại: {{ $ex['reason'] }}</span>
                                        @else
                                            Hợp lệ (dự phòng)
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-warning">Chưa có nam waiter nào được khai báo năng lực Night (Năng lực nhân viên → outlet Night).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($groups['not_skilled']->isNotEmpty())
                    <div class="small text-muted mb-3">Chưa có năng lực Night (không được chọn): {{ $groups['not_skilled']->pluck('name')->implode(', ') }}</div>
                @endif

                @if ($canEdit && ! $locked)
                    <div class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label">Lý do (bắt buộc khi chọn người bị loại)</label>
                            <input type="text" name="reason" maxlength="255" class="form-control" value="{{ old('reason') }}" placeholder="VD: đổi người theo yêu cầu nhân viên">
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                            <button class="btn btn-primary" type="submit" name="status" value="approved">Duyệt danh sách</button>
                            <button class="btn btn-dark" type="submit" name="status" value="published"
                                onclick="return confirm('Công bố sẽ khóa danh sách luân phiên tuần này. Tiếp tục?')">Duyệt & công bố (khóa)</button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Sau khi duyệt, chạy "Tạo lịch tự động" / "Tối ưu lại": chỉ những người này được xếp Night luân phiên trong tuần; outlet nguồn và Night được kiểm tra lại.</div>
                @endif
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Định biên Night từng ngày (lịch đã lưu)</h3></div>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
                <thead>
                    <tr><th>Ngày</th><th>Cần (tối thiểu / mục tiêu)</th><th>Cố định</th><th>Đa năng</th><th>Luân phiên</th><th>Khác</th><th>Thực tế</th><th>Thiếu / vượt</th></tr>
                </thead>
                <tbody>
                    @foreach ($daily as $d)
                        <tr class="{{ $d['short'] ? 'table-danger' : '' }}">
                            <td>{{ $dayNames[$d['date']->dayOfWeekIso] }} {{ $d['date']->format('d/m') }}</td>
                            <td>{{ $d['min'] }} / {{ $d['target'] }}</td>
                            @foreach (['core', 'flex', 'rotation', 'other'] as $role)
                                <td class="small" title="{{ implode(', ', $d['people'][$role]) }}">{{ count($d['people'][$role]) }}@if ($d['people'][$role]) <span class="text-muted">· {{ implode(', ', $d['people'][$role]) }}</span>@endif</td>
                            @endforeach
                            <td><strong>{{ $d['actual'] }}</strong></td>
                            <td>
                                @if ($d['short'])<span class="text-danger fw-semibold">thiếu {{ $d['short'] }}</span>
                                @elseif ($d['over'])<span class="text-warning">vượt {{ $d['over'] }}</span>
                                @else<span class="text-success">đủ</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Lịch luân phiên các tuần</h3></div>
        <div class="list-group list-group-flush">
            @foreach ($upcoming as $date => $item)
                <a class="list-group-item list-group-item-action small {{ $date === $week->toDateString() ? 'active' : '' }}" href="{{ route('roster.night.index', ['week' => $date]) }}">
                    <strong>Tuần {{ $item['week']->format('d/m') }}</strong> ·
                    @if ($item['rows']->isEmpty())
                        <span class="{{ $date === $week->toDateString() ? '' : 'text-muted' }}">chưa duyệt</span>
                    @else
                        {{ $item['rows']->map(fn ($r) => $r->employee?->name)->implode(', ') }}
                        <span class="badge bg-{{ $statusBadges[$item['rows']->first()->status] }} text-white ms-1">{{ NightRotation::STATUSES[$item['rows']->first()->status] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endsection
