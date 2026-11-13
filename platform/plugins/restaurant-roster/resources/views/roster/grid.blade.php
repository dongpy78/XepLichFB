@php
    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $summary = $grid['summary'];
    $today = now()->toDateString();
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    // Biểu tượng dùng chung (tối ưu 01/11/2026): mỗi ô trống / nút xóa từng chèn nguyên một SVG ~400 byte. Vẽ mỗi biểu tượng
    // một lần thành <symbol>, các chỗ dùng chỉ tham chiếu <use>.
    $iconBody = fn (string $name) => preg_replace(['/^\s*<svg[^>]*>/', '/<\/svg>\s*$/'], '', \Illuminate\Support\Facades\Blade::render('<x-core::icon name="' . $name . '" />'));
    $icon = fn (string $id) => '<svg class="icon" aria-hidden="true"><use href="#roster-i-' . $id . '"/></svg>';
    // Nhà hàng điều chuyển cho nhau (Villa / Gusto): ô làm ở nhà hàng khác outlet chính = người được điều chuyển (bộ lọc).
    $restaurantIds = collect($grid['stats']['outlets'] ?? [])
        ->filter(fn ($o) => in_array($o->code, (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']), true))
        ->keys()->map(fn ($id) => (int) $id)->all();

    // Bảng cảnh báo: từng vấn đề kèm nhân viên / ngày để bấm nhảy tới đúng ô.
    $alerts = [];

    foreach ($grid['rows'] as $alertRow) {
        $alertEmployee = $alertRow['employee'];

        foreach ($alertRow['check']['cells'] as $alertDate => $messages) {
            foreach ($messages as $message) {
                $alerts[] = ['level' => 'danger', 'employee' => $alertEmployee->id, 'date' => $alertDate, 'who' => $alertEmployee->name, 'text' => $message];
            }
        }

        foreach ($alertRow['check']['messages'] as $message) {
            $alerts[] = ['level' => $alertRow['check']['level'] === 'danger' ? 'danger' : 'warning', 'employee' => $alertEmployee->id, 'date' => null, 'who' => $alertEmployee->name, 'text' => $message];
        }

        foreach ($alertRow['cells'] as $alertDate => $alertShift) {
            if ($alertShift->needs_review) {
                $alerts[] = ['level' => 'warning', 'employee' => $alertEmployee->id, 'date' => $alertDate, 'who' => $alertEmployee->name, 'text' => 'Cần xem xét: ' . $alertShift->review_note];
            }
        }

        if (! $alertRow['cells'] && $alertEmployee->auto_schedule) {
            $alerts[] = ['level' => 'info', 'employee' => $alertEmployee->id, 'date' => null, 'who' => $alertEmployee->name, 'text' => 'Chưa có ô lịch nào trong tuần'];
        }
    }

    foreach ($grid['ruleConflicts'] ?? [] as $conflict) {
        $alerts[] = ['level' => 'warning', 'employee' => $conflict['employee_id'], 'date' => $conflict['date'], 'who' => $conflict['name'],
            'text' => "Ca {$conflict['notation']}: {$conflict['reason']} (Cấu hình quy tắc xếp lịch) – ô giữ nguyên, quản lý tự quyết định"];
    }

    foreach ($grid['restaurantStatus']['warnings'] ?? [] as $message) {
        $alerts[] = ['level' => 'danger', 'employee' => null, 'date' => null, 'who' => 'Nhà hàng', 'text' => $message];
    }

    $alertOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
    usort($alerts, fn ($a, $b) => [$alertOrder[$a['level']], $a['date'] ?? '9999'] <=> [$alertOrder[$b['level']], $b['date'] ?? '9999']);
@endphp

<svg style="position: absolute; width: 0; height: 0; overflow: hidden" aria-hidden="true">
    @foreach (['plus' => 'ti ti-plus', 'eraser' => 'ti ti-eraser', 'alert' => 'ti ti-alert-circle'] as $iconId => $iconName)
        <symbol id="roster-i-{{ $iconId }}" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconBody($iconName) !!}</g></symbol>
    @endforeach
</svg>

@if (! empty($grid['pendingRun']))
    @php $pending = $grid['pendingRun']; @endphp
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <x-core::icon name="ti ti-wand" />
            Có <strong>phương án tự động xếp lịch #{{ $pending->id }}</strong>
            ({{ $pending->scope_from->format('d/m') }} – {{ $pending->scope_to->format('d/m') }}) <strong>chưa được áp dụng</strong> nên chưa hiện trên bảng.
        </div>
        <a href="{{ route('roster.auto.show', $pending->id) }}" class="btn btn-info btn-sm">Xem &amp; áp dụng</a>
    </div>
@endif

@if (! empty($grid['restaurantStatus']['outlets']))
    @php
        $rs = $grid['restaurantStatus'];
        $dayShort = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    @endphp
    {{-- Hoạt động Villa / Gusto theo ngày: đóng cửa → nhân viên nhà hàng đó được xếp sang nhà hàng đang mở (chạy lại xếp tự động). --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <strong><x-core::icon name="ti ti-building-store" /> Hoạt động nhà hàng</strong>
                <span class="small text-muted">Đóng cửa một ngày → nhân viên nhà hàng đó được xếp làm ở nhà hàng đang mở (khi chạy lại xếp tự động). Cả hai mở → không tự điều chuyển.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0 align-middle" style="width: auto">
                    <tr>
                        <td></td>
                        @foreach ($rs['days'] as $date)
                            <td class="text-center small text-muted">{{ $dayShort[\Carbon\Carbon::parse($date)->dayOfWeekIso] }} {{ \Carbon\Carbon::parse($date)->format('d/m') }}</td>
                        @endforeach
                        <td></td>
                    </tr>
                    @foreach ($rs['outlets'] as $outletId => $row)
                        <tr>
                            <td class="fw-semibold pe-3">{{ $row['code'] }}@if ($row['inactive'])<div class="small text-danger">ngừng hoạt động</div>@endif</td>
                            @foreach ($row['days'] as $date => $day)
                                <td class="text-center">
                                    @php
                                        $label = $day['closed'] ? 'Đóng' : 'Mở';
                                        $cls = $day['closed'] ? 'btn-danger' : 'btn-outline-success';
                                        $confirm = $day['closed']
                                            ? "Mở cửa lại {$row['code']} ngày " . date('d/m', strtotime($date)) . '? Chạy lại xếp tự động để xếp lại nhân viên về ' . $row['code'] . '.'
                                            : "Đánh dấu {$row['code']} ĐÓNG CỬA ngày " . date('d/m', strtotime($date)) . '? Khi chạy lại xếp tự động, nhân viên ' . $row['code'] . ' được xếp sang nhà hàng đang mở.'
                                                . ($day['shifts'] ? " Hiện có {$day['shifts']} ca tại {$row['code']} ngày này – giữ nguyên cho tới khi chạy lại (ca khóa / xếp tay luôn giữ)." : '');
                                    @endphp
                                    @if ($canManage && ! $row['inactive'])
                                        <button type="button" class="btn btn-sm {{ $cls }} py-0 px-2" data-roster-action="status" data-status-outlet="{{ $outletId }}"
                                            data-status-dates="{{ $date }}" data-status-closed="{{ $day['closed'] ? 0 : 1 }}" data-confirm="{{ $confirm }}"
                                            title="{{ $day['shifts'] }} ca tại {{ $row['code'] }}">{{ $label }}</button>
                                    @else
                                        <span class="badge {{ $day['closed'] ? 'bg-danger' : 'bg-success' }} text-white">{{ $label }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="ps-2 text-nowrap">
                                @if ($canManage && ! $row['inactive'])
                                    <button type="button" class="btn btn-sm btn-link py-0" data-roster-action="status" data-status-outlet="{{ $outletId }}" data-status-dates="{{ implode(',', $rs['days']) }}"
                                        data-status-closed="1" data-confirm="Đánh dấu {{ $row['code'] }} ĐÓNG CỬA cả tuần? Khi chạy lại xếp tự động, nhân viên {{ $row['code'] }} được xếp sang nhà hàng đang mở.">Đóng cả tuần</button>
                                    <button type="button" class="btn btn-sm btn-link py-0" data-roster-action="status" data-status-outlet="{{ $outletId }}" data-status-dates="{{ implode(',', $rs['days']) }}"
                                        data-status-closed="0" data-confirm="Mở cửa {{ $row['code'] }} cả tuần?">Mở cả tuần</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @foreach ($rs['warnings'] as $warning)
                <div class="small text-danger mt-1"><x-core::icon name="ti ti-alert-triangle" /> {{ $warning }}</div>
            @endforeach
        </div>
    </div>
@endif

@if (! empty($grid['merge']))
    {{-- Thông báo cho mọi người xem bảng: tuần này Villa + Gusto hiển thị chung một khu (chỉ đổi cách hiển thị). --}}
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <x-core::icon name="ti ti-arrows-join" />
            Tuần này nhân viên <strong>{{ $grid['merge']['active_code'] }} + {{ $grid['merge']['closed_code'] }}</strong> hiển thị chung trong khu
            <strong>{{ $grid['merge']['active_code'] }}</strong>, xếp theo cấp bậc. Người có nhãn <span class="badge bg-azure-lt">{{ $grid['merge']['closed_code'] }}</span>
            vẫn thuộc outlet {{ $grid['merge']['closed_code'] }}@if (! empty($grid['merge']['auto'])) – {{ $grid['merge']['closed_code'] }} đóng cửa cả tuần nên nhân viên được xếp làm ở {{ $grid['merge']['active_code'] }}.@else – chỉ đổi cách hiển thị, lịch và xếp tự động không đổi.@endif
        </div>
        @if ($canManage && empty($grid['merge']['auto']))
            <button type="button" class="btn btn-sm" data-roster-action="merge" data-merge-outlet="">Hiển thị riêng</button>
        @endif
    </div>
@elseif ($canManage && count($grid['mergeOutlets'] ?? []) === 2)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 small text-muted">
        <span><x-core::icon name="ti ti-arrows-join" /> Hiển thị chung nhân viên {{ implode(' + ', $grid['mergeOutlets']) }} trong một khu (tuần này):</span>
        @foreach ($grid['mergeOutlets'] as $id => $code)
            <button type="button" class="btn btn-sm btn-outline-secondary" data-roster-action="merge" data-merge-outlet="{{ $id }}"
                data-confirm="Hiển thị chung nhân viên {{ implode(' + ', $grid['mergeOutlets']) }} trong khu {{ $code }} cho tuần này? Chỉ đổi cách hiển thị – lịch, outlet chính và xếp tự động không đổi.">Gộp vào {{ $code }}</button>
        @endforeach
    </div>
@endif

@if (! empty($grid['publication']))
    @php
        $publication = $grid['publication'];
        $pubQuery = array_filter(['week' => $grid['weekStart']->toDateString(), 'outlet_id' => $grid['outletId'] ?: null]);
        $pubBadge = ['draft' => 'bg-secondary', 'published' => 'bg-success', 'changed' => 'bg-warning'][$publication['status']];
        $pubLabel = \Botble\RestaurantRoster\Services\ScheduleSnapshotService::STATUS_LABELS[$publication['status']];
        $latestVersion = $publication['latest'];
    @endphp
    {{-- Lịch tuần: trạng thái công bố, xem bản chính thức, tải PNG / Excel / PDF (của bản công bố gần nhất). --}}
    <div class="card mb-3 roster-publication">
        <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <strong><x-core::icon name="ti ti-calendar-share" /> Lịch tuần:</strong>
                <span class="badge {{ $pubBadge }} text-white">{{ $pubLabel }}</span>
                @if ($latestVersion)
                    <span class="small text-muted">Bản công bố gần nhất v{{ $latestVersion->version }} · {{ $latestVersion->published_at?->format('d/m H:i') }}{{ $latestVersion->published_by_name ? ' · ' . $latestVersion->published_by_name : '' }}</span>
                @endif
                @if ($publication['status'] === 'changed')
                    <span class="small text-warning fw-semibold">Lịch đã sửa sau khi công bố – file tải vẫn là v{{ $latestVersion->version }}; công bố lại để có bản mới.</span>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-sm" href="{{ route('roster.schedule.index', $pubQuery) }}"><x-core::icon name="ti ti-eye" /> Xem lịch đã công bố{{ $publication['count'] > 1 ? ' (' . $publication['count'] . ' bản)' : '' }}</a>
                @if ($latestVersion)
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('roster.schedule.export', [...$pubQuery, 'format' => 'png']) }}">Tải ảnh PNG</a>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('roster.schedule.export', [...$pubQuery, 'format' => 'xlsx']) }}">Tải Excel</a>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('roster.schedule.export', [...$pubQuery, 'format' => 'pdf']) }}">Tải PDF</a>
                @endif
                @if ($canManage && $publication['status'] !== 'published')
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('roster.schedule.index', [...$pubQuery, 'version' => 'draft']) }}" title="Lịch đang chỉnh sửa – file ghi rõ BẢN NHÁP">Tải bản nháp</a>
                @endif
            </div>
        </div>
    </div>
@endif

<div class="row row-cards mb-3 roster-kpis">
    <div class="col-6 col-lg-3">
        <div class="card card-sm"><div class="card-body">
            <div class="text-muted small">Ca đã xếp trong tuần</div>
            <div class="h2 mb-0 font-monospace">{{ $summary['shifts'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-sm"><div class="card-body">
            <div class="text-muted small">Ca nháp chưa công bố</div>
            <div class="h2 mb-0 font-monospace {{ $summary['drafts'] ? 'text-warning' : '' }}">{{ $summary['drafts'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-sm"><div class="card-body">
            <div class="text-muted small">Cảnh báo xung đột</div>
            <div class="h2 mb-0 font-monospace {{ $summary['conflicts'] ? 'text-danger' : 'text-success' }}">{{ $summary['conflicts'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-sm"><div class="card-body">
            <div class="text-muted small">Nhân viên tăng ca</div>
            <div class="h2 mb-0 font-monospace {{ $summary['overtime'] ? 'text-warning' : '' }}">{{ $summary['overtime'] }}</div>
        </div></div>
    </div>
</div>

@if ($alerts)
    @php
        $alertCounts = array_count_values(array_column($alerts, 'level'));
        $alertLimit = 60;
    @endphp
    {{-- Cảnh báo cần xử lý: bấm một dòng để cuộn tới đúng nhân viên / ngày (bỏ bộ lọc đang che nếu cần). --}}
    <details class="card mb-3 roster-alerts" @if (count($alerts) <= 8) open @endif>
        <summary class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <strong><x-core::icon name="ti ti-alert-triangle" /> Cảnh báo cần xử lý ({{ count($alerts) }})</strong>
            @foreach (['danger' => ['bg-danger', 'vi phạm'], 'warning' => ['bg-warning', 'cần xem'], 'info' => ['bg-secondary', 'thông tin']] as $level => [$badge, $label])
                @if ($alertCounts[$level] ?? 0)
                    <span class="badge {{ $badge }} text-white">{{ $alertCounts[$level] }} {{ $label }}</span>
                @endif
            @endforeach
            <span class="small text-muted">Bấm một dòng để tới đúng ô trên bảng.</span>
        </summary>
        <div class="roster-alerts__list">
            @foreach (array_slice($alerts, 0, $alertLimit) as $alert)
                <button type="button" class="roster-alert roster-alert--{{ $alert['level'] }}"
                    @if ($alert['employee']) data-roster-jump data-employee="{{ $alert['employee'] }}" @if ($alert['date']) data-date="{{ $alert['date'] }}" @endif @endif>
                    <span class="roster-alert__who">{{ $alert['who'] }}</span>
                    @if ($alert['date'])
                        <span class="roster-alert__when">{{ $dayNames[\Carbon\Carbon::parse($alert['date'])->dayOfWeekIso] }} {{ \Carbon\Carbon::parse($alert['date'])->format('d/m') }}</span>
                    @endif
                    <span class="roster-alert__text">{{ $alert['text'] }}</span>
                </button>
            @endforeach
            @if (count($alerts) > $alertLimit)
                <div class="small text-muted px-3 py-2">… và {{ count($alerts) - $alertLimit }} cảnh báo khác – dùng bộ lọc “Có cảnh báo” để xem trên bảng.</div>
            @endif
        </div>
    </details>
@endif

<div class="card">
    @if (! count($grid['rows']))
        <div class="card-body text-center py-5">
            <x-core::icon name="ti ti-users" class="text-muted mb-2" style="width: 40px; height: 40px;" />
            <h3>Chưa có nhân viên để xếp ca</h3>
            <p class="text-muted">Thêm nhà hàng, vị trí và nhân viên trước, sau đó quay lại đây để xếp ca.</p>
            @if (Auth::user()->hasPermission('roster.settings'))
                <a href="{{ route('roster.employees.create') }}" class="btn btn-primary"><x-core::icon name="ti ti-user-plus" /> Thêm nhân viên</a>
            @endif
        </div>
    @else
        <div class="table-responsive roster-scroll">
            <table class="roster-table roster-table--compact {{ $canManage ? 'roster-table--editable' : '' }}">
                <thead>
                    <tr>
                        <th class="roster-table__staff">
                            <div class="roster-th-caption">Họ và tên</div>
                        </th>
                        @foreach ($grid['days'] as $day)
                            @php $key = $day->toDateString(); @endphp
                            <th class="{{ $key === $today ? 'is-today' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}" data-date-label="{{ $dayNames[$day->dayOfWeekIso] }}, {{ $day->format('d/m/Y') }}">
                                <div class="roster-th-day">{{ $dayNames[$day->dayOfWeekIso] }} <span class="roster-th-date">{{ $day->format('d/m') }}</span></div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $currentSection = null;
                        $nightRoles = ['core' => ['N cố định', 'Night cố định (hồ sơ nhân viên) – thuật toán tự xếp ca đêm']];
                    @endphp
                    @foreach ($grid['rows'] as $row)
                        @php
                            $employee = $row['employee'];
                            $check = $row['check'];
                        @endphp
                        @if ($currentSection !== $row['section']['key'])
                            @php
                                $currentSection = $row['section']['key'];
                                $currentStatOutlet = $row['section']['stat_outlet_id'];
                            @endphp
                            <tr class="roster-section">
                                <td class="roster-table__staff">
                                    <span class="roster-section__name">{{ mb_strtoupper($row['section']['label']) }}</span>
                                </td>
                                @foreach ($grid['days'] as $day)
                                    <td class="roster-section__day">
                                        @if ($canManage && $currentStatOutlet)
                                            <button type="button" class="roster-clear-btn"
                                                data-roster-clear="column"
                                                data-outlet="{{ $currentStatOutlet }}"
                                                data-date="{{ $day->toDateString() }}"
                                                title="Xóa lịch nhân viên outlet {{ $row['section']['label'] }} ngày {{ $dayNames[$day->dayOfWeekIso] }} {{ $day->format('d/m') }} để xếp lại">
                                                {!! $icon('eraser') !!} <span>Xóa cột</span>
                                            </button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                        @php
                            // Thông tin cho bộ lọc trên trình duyệt (tìm tên, vị trí, giới tính, ca trong tuần, điều chuyển, cảnh báo, chưa xếp).
                            $rowCodes = [];
                            $rowTransfer = false;
                            $rowWarn = $check['level'] !== 'ok' || $check['cells'];

                            foreach ($row['cells'] as $rowShift) {
                                $rowCodes[] = match ($rowShift->kind) {
                                    'shift' => $rowShift->template?->code ?? 'Ca',
                                    'off' => 'OFF',
                                    default => (string) $rowShift->notation,
                                };
                                $rowTransfer = $rowTransfer || ($rowShift->isWorking() && in_array((int) $rowShift->outlet_id, $restaurantIds, true)
                                    && (int) $rowShift->outlet_id !== (int) $employee->outlet_id);
                                $rowWarn = $rowWarn || $rowShift->needs_review;
                            }
                        @endphp
                        <tr class="roster-row" data-employee-name="{{ $employee->name }}" data-employee-id="{{ $employee->id }}" data-position="{{ $employee->position_id }}"
                            data-position-name="{{ $employee->position?->name }}" data-gender="{{ $employee->gender }}" data-codes="{{ implode(' ', array_unique($rowCodes)) }}"
                            @if ($rowTransfer) data-transfer="1" @endif @if ($rowWarn) data-warn="1" @endif @if (! $row['cells']) data-unscheduled="1" @endif>
                            <td class="roster-table__staff" @if ($check['messages']) title="{{ implode(' · ', $check['messages']) }}" @endif>
                                <div class="roster-staff__name">
                                    {{ $employee->name }}@if ($employee->birthday)<span @class(['roster-staff__birthday', 'is-upcoming' => $employee->hasUpcomingBirthdayThisMonth()]) @if ($employee->hasUpcomingBirthdayThisMonth()) title="Sinh nhật ngày {{ $employee->birthday }} (tháng này)" @endif>({{ $employee->birthday }})</span>@endif
                                    @if ($canManage)
                                        <button type="button" class="roster-clear-btn roster-clear-btn--row"
                                            data-roster-clear="row"
                                            data-employee="{{ $employee->id }}"
                                            title="Xóa lịch cả tuần của {{ $employee->name }} để xếp lại">
                                            {!! $icon('eraser') !!}
                                        </button>
                                    @endif
                                    @if ($check['level'] !== 'ok' && $check['messages'])
                                        <span class="roster-row-flag roster-row-flag--{{ $check['level'] }}">{!! $icon('alert') !!}</span>
                                    @endif
                                </div>
                                @php $nightRole = $employee->isNightCore() ? 'core' : $employee->night_role; @endphp
                                @if ($employee->position->name || $nightRole || $row['room_service'] || ! empty($row['merged_from']) || $employee->is_trainee)
                                    <div class="roster-staff__meta">
                                        {{ $employee->position->name }}
                                        @if ($role = $nightRoles[$nightRole] ?? null)
                                            <span class="badge bg-dark-lt" title="{{ $role[1] }}">{{ $role[0] }}</span>
                                        @endif
                                        @if (! empty($row['merged_from']))
                                            <span class="badge bg-azure-lt" title="Outlet chính {{ $row['merged_from'] }} – đang hiển thị chung, outlet chính không đổi">{{ $row['merged_from'] }}</span>
                                        @endif
                                        @if ($row['room_service'] === 'fixed')
                                            <span class="badge bg-orange-lt" title="Nhân viên Room Service cố định (hồ sơ) – luôn ưu tiên xếp Room Service">RS cố định</span>
                                        @elseif ($row['room_service'] === 'support')
                                            <span class="badge bg-orange-lt" title="Có thể hỗ trợ Room Service (hồ sơ) – chỉ thay khi Room Service thiếu người">RS hỗ trợ</span>
                                        @endif
                                        @if ($employee->is_trainee)
                                            <span class="badge bg-yellow-lt" title="Thực tập sinh: không tính vào định biên{{ $employee->training_to ? ', đến ' . $employee->training_to->format('d/m/Y') : '' }}">Thực tập</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            @foreach ($grid['days'] as $day)
                                @php
                                    $key = $day->toDateString();
                                    $shift = $row['cells'][$key] ?? null;
                                    $warnings = $check['cells'][$key] ?? [];
                                    $tooltip = [];

                                    if ($shift) {
                                        if ($shift->isWorking()) {
                                            $tooltip[] = trim(($shift->template?->name ?? 'Ca') . ' ' . $shift->start_at->format('H:i') . '–' . $shift->end_at->format('H:i'));

                                            if ($shift->outlet_id && (int) $shift->outlet_id !== (int) $employee->outlet_id) {
                                                $tooltip[] = 'Làm thực tế tại ' . ($grid['stats']['outlets'][$shift->outlet_id]->code ?? 'outlet khác') . ' (outlet chính ' . ($employee->outlet->code ?: '—') . ')';
                                            }

                                            foreach ($shift->tags ?: [] as $tag) {
                                                $tooltip[] = $grid['shiftTags'][$tag]['description'] ?? $tag;
                                            }

                                            if ($shift->overtime_hours > 0) {
                                                $tooltip[] = sprintf('Tăng ca %sh → kết thúc thực tế %s · %s', $fmt($shift->overtime_hours),
                                                    $shift->finishAt()?->format('H:i'),
                                                    ['approved' => 'Đã duyệt', 'rejected' => 'Từ chối'][$shift->overtime_status] ?? 'Chờ duyệt');
                                            }
                                        } elseif ($shift->kind === 'leave') {
                                            $tooltip[] = $grid['leaveTypes'][$shift->leave_type] ?? 'Nghỉ phép';
                                        }

                                        if ($shift->note) {
                                            $tooltip[] = $shift->note;
                                        }

                                        $lockLabels = [
                                            \Botble\RestaurantRoster\Models\Shift::LOCK_OFF_REQUEST => 'OFF quản lý đã cho – đã khóa',
                                            \Botble\RestaurantRoster\Models\Shift::LOCK_CONFIRMED => 'Ca đã xác nhận – đã khóa',
                                            \Botble\RestaurantRoster\Models\Shift::LOCK_SHIFT_REQUEST => 'Ca nhân viên xin – đã khóa',
                                            \Botble\RestaurantRoster\Models\Shift::LOCK_BQ => 'Tiệc BQ – đã khóa',
                                        ];
                                        $tooltip[] = ($shift->status === 'published' ? 'Đã công bố' : 'Nháp')
                                            . ($shift->is_locked ? ' · ' . ($lockLabels[$shift->lock_reason] ?? 'Đã khóa') : '')
                                            . ($shift->source === 'engine' ? ' · Do thuật toán xếp' : '');

                                        if ($shift->needs_review) {
                                            $tooltip[] = '⚠ Cần xem xét: ' . $shift->review_note;
                                        }
                                    }

                                    $variant = match (true) {
                                        ! $shift => 'empty',
                                        $shift->kind === 'off' => 'off',
                                        $shift->kind === 'leave' => 'leave',
                                        default => $shift->template?->color ?? 'slate',
                                    };
                                @endphp
                                {{-- Chỉ ghi thuộc tính có giá trị; tên nhân viên ở <tr>, nhãn ngày ở <th> (roster.js đọc từ đó). --}}
                                <td
                                    class="roster-cell {{ $day->isWeekend() ? 'is-weekend' : '' }} {{ $key === $today ? 'is-today' : '' }} {{ $warnings ? 'has-warning' : '' }}"
                                    data-employee="{{ $employee->id }}" data-date="{{ $key }}"
                                    @if ($shift) data-notation="{{ $shift->notation }}" @endif
                                    @if ($shift?->note) data-note="{{ $shift->note }}" @endif
                                    @if ($shift?->shift_template_id) data-template="{{ $shift->shift_template_id }}" @endif
                                    @if ($shift?->tags) data-tags="{{ json_encode($shift->tags) }}" @endif
                                    @if ($shift?->overtime_hours > 0) data-overtime="{{ $shift->overtime_hours }}" @endif
                                    @if ($shift?->overtime_status) data-ot-status="{{ $shift->overtime_status }}" @endif
                                    @if ($shift?->is_locked) data-locked="1" @endif
                                    @if ($shift?->isRecordedOff()) data-recorded-off="1" @endif
                                    @if ($shift?->isShiftRequest()) data-shift-requested="1" @endif
                                    @if ($shift?->needs_review) data-review="{{ $shift->review_note }}" @endif
                                    @if ($warnings) data-warnings="{{ json_encode($warnings, JSON_UNESCAPED_UNICODE) }}" @endif
                                    @if ($shift || $warnings) title="{{ implode("\n", [...$tooltip, ...$warnings]) }}" @endif
                                >
                                    <div class="roster-mini roster-mini--{{ $variant }} {{ $shift?->status === 'draft' ? 'is-draft' : '' }} {{ $shift?->is_locked ? 'is-locked' : '' }} {{ $shift?->isRecordedOff() ? 'is-recorded-off' : '' }} {{ $shift?->needs_review ? 'needs-review' : '' }}">
                                        @if ($shift && $shift->kind === 'shift' && ! $shift->display_notation)
                                            <span class="roster-mini__code">{{ $shift->template?->code ?? 'Ca' }}</span>
                                            @if ($shift->tags)
                                                <span class="roster-mini__tags">{{ collect($shift->tags)->map(fn ($tag) => "({$tag})")->implode('') }}</span>
                                            @endif
                                            @if ($shift->overtime_hours > 0)
                                                <span class="roster-mini__ot roster-mini__ot--{{ $shift->overtime_status ?: 'pending' }}">+{{ $fmt($shift->overtime_hours) }}</span>
                                            @endif
                                        @elseif ($shift)
                                            <span class="roster-mini__code">{{ $shift->notation }}</span>
                                        @else
                                            {!! $icon('plus') !!}
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @include('plugins/restaurant-roster::roster.partials.staff-board')
                </tbody>
            </table>
        </div>
    @endif
</div>
