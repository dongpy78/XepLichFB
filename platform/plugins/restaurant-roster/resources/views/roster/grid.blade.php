@php
    $dayNames = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    $summary = $grid['summary'];
    $today = now()->toDateString();
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
@endphp

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
                            <th class="{{ $key === $today ? 'is-today' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}">
                                <div class="roster-th-day">{{ $dayNames[$day->dayOfWeekIso] }} <span class="roster-th-date">{{ $day->format('d/m') }}</span></div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php $currentOutlet = null; @endphp
                    @foreach ($grid['rows'] as $row)
                        @php
                            $employee = $row['employee'];
                            $check = $row['check'];
                        @endphp
                        @if ($currentOutlet !== $employee->outlet_id)
                            @if ($currentOutlet !== null)
                                @include('plugins/restaurant-roster::roster.partials.section-stats', ['outletId' => $currentOutlet])
                            @endif
                            @php
                                $currentOutlet = $employee->outlet_id;
                            @endphp
                            <tr class="roster-section">
                                <td class="roster-table__staff">
                                    <span class="roster-section__name">{{ mb_strtoupper($employee->outlet->code ?: $employee->outlet->name ?: 'Chưa có outlet') }}</span>
                                </td>
                                @foreach ($grid['days'] as $day)
                                    <td class="roster-section__day">
                                        @if ($canManage)
                                            <button type="button" class="roster-clear-btn"
                                                data-roster-clear="column"
                                                data-outlet="{{ $employee->outlet_id }}"
                                                data-date="{{ $day->toDateString() }}"
                                                title="Xóa lịch {{ $employee->outlet->code ?: $employee->outlet->name }} ngày {{ $dayNames[$day->dayOfWeekIso] }} {{ $day->format('d/m') }} để xếp lại">
                                                <x-core::icon name="ti ti-eraser" /> <span>Xóa cột</span>
                                            </button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                        <tr>
                            <td class="roster-table__staff" @if ($check['messages']) title="{{ implode(' · ', $check['messages']) }}" @endif>
                                <div class="roster-staff__name">
                                    {{ $employee->name }}@if ($employee->birthday)<span @class(['roster-staff__birthday', 'is-upcoming' => $employee->hasUpcomingBirthdayThisMonth()]) @if ($employee->hasUpcomingBirthdayThisMonth()) title="Sinh nhật ngày {{ $employee->birthday }} (tháng này)" @endif>({{ $employee->birthday }})</span>@endif
                                    @if ($canManage)
                                        <button type="button" class="roster-clear-btn roster-clear-btn--row"
                                            data-roster-clear="row"
                                            data-employee="{{ $employee->id }}"
                                            title="Xóa lịch cả tuần của {{ $employee->name }} để xếp lại">
                                            <x-core::icon name="ti ti-eraser" />
                                        </button>
                                    @endif
                                    @if ($check['level'] !== 'ok' && $check['messages'])
                                        <span class="roster-row-flag roster-row-flag--{{ $check['level'] }}"><x-core::icon name="ti ti-alert-circle" /></span>
                                    @endif
                                </div>
                                @if ($employee->position->name)
                                    <div class="roster-staff__meta">{{ $employee->position->name }}</div>
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
                                <td
                                    class="roster-cell {{ $day->isWeekend() ? 'is-weekend' : '' }} {{ $key === $today ? 'is-today' : '' }} {{ $warnings ? 'has-warning' : '' }}"
                                    data-employee="{{ $employee->id }}"
                                    data-employee-name="{{ $employee->name }}"
                                    data-date="{{ $key }}"
                                    data-date-label="{{ $dayNames[$day->dayOfWeekIso] }}, {{ $day->format('d/m/Y') }}"
                                    data-note="{{ $shift?->note }}"
                                    data-notation="{{ $shift?->notation }}"
                                    data-template="{{ $shift?->shift_template_id }}"
                                    data-tags="{{ json_encode($shift?->tags ?: []) }}"
                                    data-overtime="{{ $shift?->overtime_hours ?: '' }}"
                                    data-ot-status="{{ $shift?->overtime_status }}"
                                    data-locked="{{ $shift?->is_locked ? 1 : 0 }}"
                                    data-state="{{ $shift?->cellState() }}"
                                    data-recorded-off="{{ $shift?->isRecordedOff() ? 1 : 0 }}"
                                    data-review="{{ $shift?->needs_review ? $shift->review_note : '' }}"
                                    data-warnings="{{ json_encode($warnings, JSON_UNESCAPED_UNICODE) }}"
                                    @if ($shift || $warnings) title="{{ implode("\n", [...$tooltip, ...$warnings]) }}" @endif
                                >
                                    <div class="roster-mini roster-mini--{{ $variant }} {{ $shift?->status === 'draft' ? 'is-draft' : '' }} {{ $shift?->is_locked ? 'is-locked' : '' }} {{ $shift?->isRecordedOff() ? 'is-recorded-off' : '' }} {{ $shift?->needs_review ? 'needs-review' : '' }}">
                                        @if ($shift && $shift->kind === 'shift')
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
                                            <x-core::icon name="ti ti-plus" />
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($currentOutlet !== null)
                        @include('plugins/restaurant-roster::roster.partials.section-stats', ['outletId' => $currentOutlet])
                    @endif
                    @include('plugins/restaurant-roster::roster.partials.lobby-stats')
                </tbody>
            </table>
        </div>
    @endif
</div>
