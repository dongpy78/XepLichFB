@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $statusBadges = ['active' => 'success', 'draft' => 'secondary', 'inactive' => 'dark'];
    $statusLabels = \Botble\RestaurantRoster\Models\StaffingRequirement::STATUSES;
    $canEdit = Auth::user()->hasPermission('roster.settings');
@endphp

@section('content')
    <div class="row row-cards mb-3 roster-kpis">
        @foreach ([
            ['Outlet có định biên', $overview['outlets_configured'], null, 'Outlet có ít nhất một ngày được cấu hình trong tuần đang xem'],
            ['Outlet cấu hình đầy đủ', $overview['outlets_complete'], $overview['outlets_complete'] < $overview['outlets_configured'] ? 'warning' : 'success', 'Mọi ca có người làm của outlet đều có định biên đủ 7 ngày, không xung đột'],
            ['Ô ngày × ca chưa cấu hình', $overview['missing_cells'], $overview['missing_cells'] ? 'warning' : 'success', 'Ca có người làm (4 tuần gần nhất) hoặc đã có dòng định biên nhưng ngày đó không có định biên đang áp dụng'],
            ['Ô xung đột', $overview['conflict_cells'], $overview['conflict_cells'] ? 'danger' : 'success', 'Hai dòng đang áp dụng cùng mức ưu tiên, giá trị khác nhau – thuật toán không chạy cho tới khi sửa'],
            ['Cần kiểm tra / nháp', $overview['needs_review'] . ' / ' . $overview['drafts'], $overview['needs_review'] ? 'warning' : null, 'Đang áp dụng nhưng là gợi ý chưa xác nhận / bản nháp chưa dùng'],
            ['Cảnh báo thiếu (30 ngày)', $overview['shortage_warnings'], $overview['shortage_warnings'] ? 'warning' : 'success', 'Số ô thiếu định biên trong các lần xếp / kiểm tra lịch 30 ngày gần nhất'],
        ] as [$label, $value, $color, $hint])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card card-sm h-100" title="{{ $hint }}">
                    <div class="card-body">
                        <div class="text-muted small">{{ $label }}</div>
                        <div class="h2 mb-0 font-monospace {{ $color ? 'text-' . $color : '' }}">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if (config('plugins.restaurant-roster.general.scheduling.staffing_mode', 'balance') === 'balance')
        <div class="alert alert-info">
            Nhà hàng thông thường (Gusto, Villa…) không còn định biên: thuật toán cân bằng Waiter / quản lý M6 – A2 theo số người thực tế.
            Trang này chỉ dùng cho <strong>Room Service, Kid-Deline</strong> (Night: thuật toán chỉ xếp người bật "Night cố định" trong hồ sơ, ≥ 1 người / đêm;
            người luân phiên quản lý xếp tay trên Bảng xếp ca). Lobby ở <a href="{{ route('roster.coverage.index') }}">Định biên quầy Lobby</a>.
            Dòng cũ của nhà hàng thông thường được giữ nhưng không dùng khi xếp lịch.
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div>
                <h3 class="card-title mb-0">Định biên áp dụng tuần {{ $week->format('d/m') }} – {{ $week->addDays(6)->format('d/m/Y') }}</h3>
                <div class="text-muted small">Tối thiểu / mục tiêu · ♂ nam · ♀ nữ waiter · nhóm vị trí. Bấm một ô để mở dòng định biên.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-sm" href="{{ route('roster.requirements.index', [...$filters, 'week' => $week->subWeek()->toDateString()]) }}">‹ Tuần trước</a>
                <a class="btn btn-sm" href="{{ route('roster.requirements.index', [...$filters, 'week' => $week->addWeek()->toDateString()]) }}">Tuần sau ›</a>
                @if ($canEdit)
                    <a href="{{ route('roster.requirements.create') }}" class="btn btn-sm btn-primary"><x-core::icon name="ti ti-plus" /> Thêm định biên</a>
                @endif
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter card-table roster-req-matrix">
                <thead>
                    <tr>
                        <th>Outlet · ca</th>
                        @foreach (range(0, 6) as $offset)
                            @php $day = $week->addDays($offset); @endphp
                            <th class="text-center {{ $day->isWeekend() ? 'bg-light' : '' }}">{{ $dayNames[$day->dayOfWeekIso] }} {{ $day->format('d/m') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($matrix as $row)
                        <tr>
                            <td class="text-nowrap fw-semibold">{{ $row['outlet']->code ?: $row['outlet']->name }} · {{ $row['template']->code }}</td>
                            @foreach ($row['cells'] as $date => $cell)
                                <td class="text-center small">
                                    @switch($cell['state'])
                                        @case('ok')
                                            <a href="{{ $canEdit && $cell['id'] ? route('roster.requirements.edit', $cell['id']) : '#' }}" class="text-reset text-decoration-none">
                                                <div class="font-monospace fw-bold">{{ $cell['min'] }}/{{ $cell['target'] }}</div>
                                                <div class="text-muted">
                                                    @if ($cell['male'])♂{{ $cell['male'] }} @endif
                                                    @if ($cell['female'])♀{{ $cell['female'] }} @endif
                                                    {{ implode(' · ', $cell['groups']) }}
                                                </div>
                                            </a>
                                            @break
                                        @case('conflict')
                                            <span class="badge bg-danger-lt" title="Dòng #{{ implode(', #', $cell['ids']) }} cùng mức ưu tiên, giá trị khác nhau">Xung đột</span>
                                            @break
                                        @case('closed')
                                            <span class="text-muted">Đóng cửa</span>
                                            @break
                                        @default
                                            @if ($canEdit)
                                                <a href="{{ route('roster.requirements.create', ['outlet_id' => $row['outlet']->id, 'shift_template_id' => $row['template']->id]) }}" class="badge bg-warning-lt text-decoration-none">Chưa cấu hình</a>
                                            @else
                                                <span class="badge bg-warning-lt">Chưa cấu hình</span>
                                            @endif
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có outlet × ca nào có người làm hoặc có định biên.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('roster.requirements.index') }}" class="row g-2 align-items-end w-100">
                <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-outlet">Outlet</label>
                    <select class="form-select form-select-sm" id="f-outlet" name="outlet_id">
                        <option value="">Tất cả</option>
                        @foreach ($outlets as $outlet)
                            <option value="{{ $outlet->id }}" @selected(($filters['outlet_id'] ?? '') == $outlet->id)>{{ $outlet->code ?: $outlet->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-shift">Ca</label>
                    <select class="form-select form-select-sm" id="f-shift" name="shift_template_id">
                        <option value="">Tất cả</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}" @selected(($filters['shift_template_id'] ?? '') == $template->id)>{{ $template->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-status">Trạng thái</label>
                    <select class="form-select form-select-sm" id="f-status" name="status">
                        <option value="">Tất cả</option>
                        @foreach ($statusLabels + ['needs_review' => 'Cần kiểm tra (gợi ý chưa xác nhận)'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-date">Áp dụng vào ngày</label>
                    <input type="date" class="form-control form-control-sm" id="f-date" name="on_date" value="{{ $filters['on_date'] ?? '' }}">
                </div>
                <div class="col-8 col-md-2">
                    <label class="form-label small mb-1" for="f-q">Tìm trong ghi chú</label>
                    <input type="search" class="form-control form-control-sm" id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="VD: tầng trên">
                </div>
                <div class="col-4 col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary">Lọc</button>
                    <a href="{{ route('roster.requirements.index', ['week' => $week->toDateString()]) }}" class="btn btn-sm">Xóa lọc</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>#</th><th>Outlet · ca</th><th>Áp dụng</th><th>Hiệu lực</th>
                        <th class="text-center" title="Tối thiểu / mục tiêu">Người</th>
                        <th class="text-center">Nam</th><th class="text-center">Nữ waiter</th><th>Nhóm vị trí</th>
                        <th>Trạng thái</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="text-muted">{{ $row->id }}</td>
                            <td class="text-nowrap fw-semibold">{{ $row->outlet?->code }} · {{ $row->template?->code }}</td>
                            <td>{{ $row->scheduleLabel() }}
                                @if ($row->count_start_time)<div class="text-muted small">Khung đếm {{ substr($row->count_start_time, 0, 5) }}–{{ substr($row->count_end_time, 0, 5) }}</div>@endif
                            </td>
                            <td class="small">{{ $row->effectiveLabel() }}</td>
                            <td class="text-center font-monospace"><strong>{{ $row->min_staff }}</strong> / {{ $row->target_staff }}{{ $row->max_staff !== null ? ' ≤' . $row->max_staff : '' }}</td>
                            <td class="text-center font-monospace">{{ $row->min_male === null ? '—' : $row->min_male . ' / ' . ($row->target_male ?? $row->min_male) }}</td>
                            <td class="text-center font-monospace">{{ $row->min_female === null ? '—' : $row->min_female . ' / ' . ($row->target_female ?? $row->min_female) }}</td>
                            <td class="small">
                                @forelse ($row->positions as $position)
                                    <div>{{ $position->label() }}: <span class="font-monospace">{{ $position->min_staff ?? '?' }}/{{ $position->target_staff ?? $position->min_staff ?? '?' }}</span></div>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td>
                                <span class="badge bg-{{ $statusBadges[$row->status] ?? 'secondary' }}-lt">{{ $statusLabels[$row->status] ?? $row->status }}</span>
                                @if ($row->status === 'active' && ! $row->is_confirmed)
                                    <div><span class="badge bg-warning-lt mt-1" title="Gợi ý học từ lịch cũ – quản lý chưa xác nhận">Cần kiểm tra</span></div>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($canEdit)
                                    <a href="{{ route('roster.requirements.edit', $row->id) }}" class="btn btn-sm" title="Sửa"><x-core::icon name="ti ti-edit" /></a>
                                    <a href="{{ route('roster.requirements.create', ['copy' => $row->id]) }}" class="btn btn-sm" title="Sao chép sang ca / ngày khác"><x-core::icon name="ti ti-copy" /></a>
                                @endif
                                <a href="{{ route('roster.requirements.history', $row->id) }}" class="btn btn-sm" title="Lịch sử chỉnh sửa"><x-core::icon name="ti ti-history" /></a>
                                @if ($canEdit)
                                    @if ($row->status === 'active')
                                        <form method="POST" action="{{ route('roster.requirements.status', $row->id) }}" class="d-inline"
                                            onsubmit="var r = prompt('Ngừng hiệu lực định biên #{{ $row->id }}? Lý do (tùy chọn):'); if (r === null) return false; this.reason.value = r;">
                                            @csrf
                                            <input type="hidden" name="status" value="inactive"><input type="hidden" name="reason">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Ngừng hiệu lực (giữ lịch sử)"><x-core::icon name="ti ti-player-pause" /></button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('roster.requirements.status', $row->id) }}" class="d-inline"
                                            onsubmit="return confirm('Áp dụng định biên #{{ $row->id }}? Thuật toán sẽ dùng dòng này từ lần chạy tới.')">
                                            @csrf
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Áp dụng"><x-core::icon name="ti ti-player-play" /></button>
                                        </form>
                                    @endif
                                    @if ($row->status === 'draft')
                                        <form method="POST" action="{{ route('roster.requirements.destroy', $row->id) }}" class="d-inline" onsubmit="return confirm('Xóa bản nháp #{{ $row->id }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa bản nháp"><x-core::icon name="ti ti-trash" /></button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">Không có dòng định biên nào khớp bộ lọc.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="text-muted small">{{ $rows->firstItem() }}–{{ $rows->lastItem() }} / {{ $rows->total() }} dòng</span>
                {{ $rows->links() }}
            </div>
        @endif
    </div>
@endsection
