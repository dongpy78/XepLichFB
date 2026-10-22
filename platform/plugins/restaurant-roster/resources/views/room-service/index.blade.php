@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    use Botble\RestaurantRoster\Models\RoomServiceStaff;

    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $leads = $current->where('role', RoomServiceStaff::ROLE_LEAD)->sortBy(fn ($r) => $r->template?->code);
    $person = fn ($e) => $e ? $e->name . ($e->outlet?->code ? ' · ' . $e->outlet->code : '') : '—';
@endphp

@section('content')
    @if (! $room)
        <div class="alert alert-danger">Chưa có outlet Room Service (mã ROOM / RS) – tạo ở Nhà hàng / Outlet.</div>
    @endif

    @if ($affected)
        <div class="alert alert-warning">
            <strong>Lịch đã xếp cần xem lại sau khi đổi người chuyên trách</strong> (phần mềm không tự sửa lịch – chạy "Tối ưu lại" cho lịch chưa khóa, hoặc giữ nguyên):
            <ul class="mb-1">
                @foreach ($affected as $a)
                    <li>{{ $a['text'] }}@if ($a['published']) · <strong>đã công bố</strong>@endif @if ($a['locked']) · đã khóa @endif</li>
                @endforeach
            </ul>
            <a href="{{ route('roster.auto.index') }}">Mở Tự động xếp lịch</a>
        </div>
    @endif

    <div class="row row-cards mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Đang áp dụng (hôm nay)</h3></div>
                <div class="card-body small">
                    @foreach ([RoomServiceStaff::ROLE_LEAD => 'Chuyên trách', RoomServiceStaff::ROLE_BACKUP => 'Dự phòng (thay trước)', RoomServiceStaff::ROLE_SUBSTITUTE => 'Có thể thay thế (đã xác nhận kỹ năng)'] as $role => $title)
                        <div class="fw-semibold mt-2">{{ $title }}</div>
                        @forelse ($role === RoomServiceStaff::ROLE_LEAD ? $leads : $current->where('role', $role) as $row)
                            <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                                <span>
                                    @if ($row->template)<span class="badge bg-blue-lt">{{ $row->template->code }}</span>@endif
                                    {{ $person($row->employee) }}
                                    <span class="text-muted">· {{ $row->effective_from?->format('d/m/Y') ?? '…' }} → {{ $row->effective_to?->format('d/m/Y') ?? 'không thời hạn' }}</span>
                                </span>
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('roster.room-service.end', $row->id) }}" onsubmit="return confirm('Kết thúc hiệu lực dòng này? Lịch sử vẫn được giữ.')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Kết thúc</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <div class="text-muted">—</div>
                        @endforelse
                    @endforeach
                    @if ($skillOnly->isNotEmpty())
                        <div class="fw-semibold mt-2">Có năng lực Room Service (khai ở Năng lực nhân viên)</div>
                        <div>{{ $skillOnly->map(fn ($e) => $person($e))->implode('; ') }}</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Thêm / đổi người</h3></div>
                <div class="card-body">
                    @if ($canEdit)
                        <form method="POST" action="{{ route('roster.room-service.store') }}" class="row g-2">
                            @csrf
                            <div class="col-12">
                                <label class="form-label">Nhân viên</label>
                                <select name="employee_id" class="form-select" required>
                                    <option value="">— Chọn —</option>
                                    @foreach ($employees as $e)
                                        <option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $person($e) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vai trò</label>
                                <select name="role" class="form-select" required>
                                    @foreach (RoomServiceStaff::ROLES as $key => $label)
                                        <option value="{{ $key }}" @selected(old('role') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ca (khi là chuyên trách)</label>
                                <select name="shift_template_id" class="form-select">
                                    <option value="">—</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t->id }}" @selected(old('shift_template_id') == $t->id)>{{ $t->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hiệu lực từ</label>
                                <input type="date" name="effective_from" class="form-control" value="{{ old('effective_from', now()->toDateString()) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Đến (trống = không thời hạn)</label>
                                <input type="date" name="effective_to" class="form-control" value="{{ old('effective_to') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Ghi chú / kỹ năng đã xác nhận</label>
                                <input type="text" name="note" maxlength="255" class="form-control" value="{{ old('note') }}">
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Lưu</button>
                                <div class="small text-muted mt-1">Chọn chuyên trách cho một ca đã có người: người cũ tự kết thúc hiệu lực vào hôm trước ngày bắt đầu, lịch sử được giữ; lịch đã xếp không bị sửa.</div>
                            </div>
                        </form>
                    @else
                        <div class="text-muted">Cần quyền cài đặt để thay đổi.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Ca Room Service 14 ngày từ {{ $from->format('d/m') }}</h3>
            <form method="GET" class="ms-auto d-flex gap-2"><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm"><button class="btn btn-sm btn-outline-primary">Xem</button></form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
                <thead><tr><th>Ngày</th><th>Ca</th><th>Chuyên trách dự kiến</th><th>Thực tế</th><th>Lý do thay</th><th>Cảnh báo</th></tr></thead>
                <tbody>
                    @forelse ($daily as $d)
                        <tr class="{{ $d['warnings'] ? 'table-warning' : '' }}">
                            <td>{{ $dayNames[$d['date']->dayOfWeekIso] }} {{ $d['date']->format('d/m') }}</td>
                            <td>{{ $d['template']?->code }}</td>
                            <td>{{ $d['lead']?->name ?? '—' }}</td>
                            <td class="small">
                                @foreach ($d['actual'] as $a)
                                    <div>{{ $a['name'] }}@if ($a['substitute']) <span class="badge bg-orange-lt">thay · {{ $a['source'] }}</span>@endif</div>
                                @endforeach
                            </td>
                            <td class="small">{{ $d['reason'] }}</td>
                            <td class="small text-danger">{{ implode(' ', $d['warnings']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Chưa có người chuyên trách nào được cấu hình.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Lịch sử cấu hình</h3></div>
                <div class="list-group list-group-flush small">
                    @foreach ($history as $row)
                        <div class="list-group-item {{ $row->coversDate(now()->toDateString()) ? '' : 'text-muted' }}">
                            {{ $row->employee?->name }} · {{ $row->roleLabel() }} · {{ $row->effective_from?->format('d/m/Y') ?? '…' }} → {{ $row->effective_to?->format('d/m/Y') ?? 'không thời hạn' }}
                            @if ($row->note)<div class="text-muted">{{ $row->note }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Nhật ký thay đổi</h3></div>
                <div class="list-group list-group-flush small">
                    @foreach ($logs as $log)
                        <div class="list-group-item">{{ $log->created_at?->format('d/m/Y H:i') }} · {{ $log->employee?->name }} · {{ $log->reason }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
