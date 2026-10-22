@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    use Botble\RestaurantRoster\Models\NightRotation;

    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $statusBadges = ['proposed' => 'warning', 'approved' => 'success', 'published' => 'dark'];
    $locked = $saved->contains('status', NightRotation::STATUS_PUBLISHED);
    $selectedIds = $preview['ids'] ?? old('employee_ids', $saved->isNotEmpty()
        ? $saved->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        : collect($proposal['selected'])->map(fn ($row) => $row['employee']->getKey())->all());
    $selectedIds = array_map('intval', (array) $selectedIds);
    $excludedById = collect($proposal['excluded'])->keyBy(fn ($row) => $row['employee']->getKey());
    $scoreById = collect([...$proposal['selected'], ...$proposal['candidates']])->keyBy(fn ($row) => $row['employee']->getKey());
    $proposedIds = collect($proposal['selected'])->map(fn ($r) => $r['employee']->getKey())->all();
    $days = $staffing['days'];
    $perPerson = $staffing['per_person'];
    $person = fn ($e) => $e->name . ' · ' . ($e->outlet?->code ?: '—');
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
                Night đúng <strong>3</strong> người / ngày, ít nhất <strong>1 Night cứng</strong> ·
                <strong>{{ $perWeek }}</strong> người luân phiên × 2 đêm liền nhau (3 khi cần), hôm sau OFF
            </div>
        </div>
    </div>

    @if ($canEdit)
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="small">
                    <strong>Reset lịch Night tuần {{ $week->format('d/m') }}</strong> (để chạy lại thuật toán):
                    xóa <strong>{{ $resetScope['engine'] }}</strong> ca đêm do thuật toán xếp (nháp, chưa khóa)
                    và <strong>{{ $resetScope['proposed'] }}</strong> dòng luân phiên đề xuất.
                    Giữ nguyên {{ $resetScope['kept_locked'] }} ca đã khóa, {{ $resetScope['kept_published'] }} ca đã công bố, {{ $resetScope['kept_manual'] }} ca xếp tay,
                    {{ $resetScope['published'] }} người luân phiên đã công bố; không động tới tuần khác, outlet khác, OFF, nhân viên, cấu hình.
                </div>
                <form method="POST" action="{{ route('roster.night.reset') }}" class="d-flex gap-2 align-items-center"
                    onsubmit="return confirm('Reset lịch Night tuần {{ $week->format('d/m') }}?

• Xóa {{ $resetScope['engine'] }} ca đêm do thuật toán xếp (nháp, chưa khóa).
• Xóa danh sách luân phiên đề xuất' + (this.include_approved && this.include_approved.checked ? ' và {{ $resetScope['approved'] }} người đã duyệt' : '') + '.
• Giữ ca đã khóa / đã công bố / xếp tay.

Thao tác được ghi nhật ký. Tiếp tục?')">
                    @csrf
                    <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                    <input type="hidden" name="confirm" value="1">
                    @if ($resetScope['approved'])
                        <label class="form-check small mb-0"><input type="checkbox" class="form-check-input" name="include_approved" value="1"> Xóa cả {{ $resetScope['approved'] }} người luân phiên đã duyệt</label>
                    @endif
                    <button class="btn btn-outline-danger" type="submit">Reset lịch Night</button>
                </form>
            </div>
        </div>
    @endif

    <div class="row row-cards mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Night cứng ({{ $groups['core']->count() }})</h3></div>
                <div class="card-body small">
                    <div class="text-muted mb-2">Người có ca cứng là ca đêm (chọn ở mục Ca cứng của nhân viên). Mỗi ngày phải có ít nhất 1 người.</div>
                    @forelse ($groups['core'] as $e)
                        <div>{{ $person($e) }} · ca cứng {{ $e->fixedShift?->code }}</div>
                    @empty
                        <div class="text-danger">Chưa có ai – chọn Ca cứng N cho 2 nhân viên Night cứng.</div>
                    @endforelse
                    <div class="text-muted mt-3 mb-1">Nhân viên đa năng (outlet Night, trừ Night cứng / Room Service chuyên trách) – được mượn trước tiên khi outlet thiếu nam / nữ tối thiểu:</div>
                    <div>{{ $groups['flex']->isNotEmpty() ? $groups['flex']->map(fn ($e) => $e->name . ($e->gender === 'female' ? ' (nữ)' : ' (nam)'))->implode(', ') : '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="card-title">Room Service chuyên trách ({{ $groups['room_service']->count() }})</h3>
                    <a class="ms-auto small" href="{{ route('roster.room-service.index') }}">Nhân sự Room Service</a>
                </div>
                <div class="card-body small">
                    <div class="text-muted mb-2">Không vào danh sách luân phiên Night.</div>
                    @forelse ($groups['room_service'] as $e)
                        <div>{{ $person($e) }}</div>
                    @empty
                        <div class="text-warning">Chưa cấu hình người chuyên trách Room Service.</div>
                    @endforelse
                </div>
            </div>
        </div>
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
            @if ($preview)
                <div class="alert {{ $preview['warnings'] ? 'alert-danger' : 'alert-success' }} py-2">
                    <strong>Xem trước ảnh hưởng:</strong>
                    @forelse ($preview['warnings'] as $warning)
                        <div>• {{ $warning }}</div>
                    @empty
                        Danh sách hợp lệ – đủ điều kiện, outlet nguồn vẫn đủ định biên tối thiểu. Có thể duyệt.
                    @endforelse
                </div>
            @endif
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
                                <th>Nam waiter đủ điều kiện (Villa / Gusto / Night)</th>
                                <th>Tuần Night ({{ \Botble\RestaurantRoster\Services\NightStaffService::HISTORY_WEEKS }} tuần qua)</th>
                                <th>Đêm tuần này</th>
                                <th>Đánh giá</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($groups['rotation'] as $e)
                                @php
                                    $id = $e->getKey();
                                    $h = $history[$id] ?? ['weeks' => 0, 'last_week' => false, 'list' => []];
                                    $ex = $excludedById[$id] ?? null;
                                @endphp
                                <tr class="{{ $ex ? 'text-muted' : '' }}">
                                    <td><input type="checkbox" class="form-check-input" name="employee_ids[]" value="{{ $id }}" @checked(in_array($id, $selectedIds, true)) @disabled($locked || ! $canEdit)></td>
                                    <td>{{ $person($e) }}</td>
                                    <td>
                                        {{ $h['weeks'] }}
                                        @if ($h['last_week'])<span class="badge bg-warning text-white ms-1">tuần trước</span>@endif
                                        @if ($h['list'])<span class="text-muted small ms-1">({{ implode(', ', $h['list']) }})</span>@endif
                                    </td>
                                    <td>{{ array_key_exists($id, $perPerson) ? $perPerson[$id] . ' đêm' : '—' }}</td>
                                    <td class="small">
                                        @if (in_array($id, $proposedIds, true))
                                            <span class="badge bg-success text-white">Đề xuất</span>
                                        @elseif ($ex)
                                            <span class="text-danger">Không chọn được: {{ $ex['reason'] }}</span>
                                        @else
                                            Hợp lệ (dự phòng)
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($canEdit)
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" form="toggle-{{ $id }}" title="Tạm ngừng tham gia luân phiên Night">Tắt</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-warning">Chưa có nam waiter nào đủ điều kiện (cần năng lực Night ở Năng lực nhân viên).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($canEdit && ! $locked)
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Lý do (bắt buộc khi chọn người không chọn được)</label>
                            <input type="text" name="reason" maxlength="255" class="form-control" value="{{ old('reason') }}" placeholder="VD: đổi người theo yêu cầu nhân viên">
                        </div>
                        <div class="col-md-7 d-flex flex-wrap gap-2">
                            <button class="btn btn-outline-primary" type="submit" name="status" value="preview">Xem trước ảnh hưởng</button>
                            <button class="btn btn-primary" type="submit" name="status" value="approved">Duyệt danh sách</button>
                            <button class="btn btn-dark" type="submit" name="status" value="published"
                                onclick="return confirm('Công bố sẽ khóa danh sách luân phiên tuần này. Tiếp tục?')">Duyệt & công bố (khóa)</button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Sau khi duyệt, chạy "Tạo lịch tự động" / "Tối ưu lại": mỗi người được chọn làm 2 đêm liền nhau (3 khi cần đủ 3 người / ngày), hôm sau OFF, các ngày còn lại làm ca chính ở outlet.</div>
                @endif
            </form>

            @foreach ($groups['rotation'] as $e)
                <form id="toggle-{{ $e->getKey() }}" method="POST" action="{{ route('roster.night.toggle', $e->getKey()) }}" class="d-none">
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                </form>
            @endforeach

            @if ($groups['disabled']->isNotEmpty())
                <div class="small mt-3">
                    <strong>Đã tắt luân phiên:</strong>
                    @foreach ($groups['disabled'] as $e)
                        <form method="POST" action="{{ route('roster.night.toggle', $e->getKey()) }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="enabled" value="1">
                            <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                            {{ $person($e) }} @if ($canEdit)<button class="btn btn-link btn-sm p-0" type="submit">bật lại</button>@endif;
                        </form>
                    @endforeach
                </div>
            @endif
            @if ($groups['ineligible']->isNotEmpty())
                <div class="small mt-2"><strong>Có năng lực Night nhưng không được chọn luân phiên:</strong>
                    @foreach ($groups['ineligible'] as $row)
                        <div>• {{ $person($row['employee']) }} – {{ $row['reason'] }} <a href="{{ route('roster.employees.edit', $row['employee']->getKey()) }}">sửa hồ sơ</a></div>
                    @endforeach
                </div>
            @endif
            @if ($groups['not_skilled']->isNotEmpty())
                <div class="small text-muted mt-2">Chưa có năng lực Night (không được chọn): {{ $groups['not_skilled']->pluck('name')->implode(', ') }}</div>
            @endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Night từng ngày (lịch đã lưu)</h3></div>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
                <thead>
                    <tr><th>Ngày</th><th>Cần</th><th>Night cứng</th><th>Luân phiên</th><th>Khác</th><th>Thực tế</th><th>Đánh giá</th></tr>
                </thead>
                <tbody>
                    @foreach ($days as $d)
                        <tr class="{{ $d['short'] || ($d['actual'] && ! $d['has_core']) ? 'table-danger' : '' }}">
                            <td>{{ $dayNames[$d['date']->dayOfWeekIso] }} {{ $d['date']->format('d/m') }}</td>
                            <td>{{ $d['min'] }}</td>
                            @foreach (['core', 'rotation', 'other'] as $role)
                                <td class="small">{{ count($d['people'][$role]) }}@if ($d['people'][$role]) <span class="text-muted">· {{ implode(', ', $d['people'][$role]) }}</span>@endif</td>
                            @endforeach
                            <td><strong>{{ $d['actual'] }}</strong></td>
                            <td class="small">
                                @if ($d['short'])<div class="text-danger fw-semibold">Night ngày {{ $dayNames[$d['date']->dayOfWeekIso] }} chỉ có {{ $d['actual'] }} người – cần thêm {{ $d['short'] }} để đủ {{ $d['min'] }}.</div>@endif
                                @if ($d['over'])<div class="text-warning">Vượt {{ $d['over'] }} người.</div>@endif
                                @if ($d['actual'] && ! $d['has_core'])<div class="text-danger">Không có nhân viên Night cứng điều phối.</div>@endif
                                @if (! $d['short'] && ! $d['over'] && $d['has_core'])<span class="text-success">Đủ</span>@endif
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
