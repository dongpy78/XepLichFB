@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $statusLabels = [
        'running' => ['Đang chạy', 'secondary'], 'feasible' => ['Hợp lệ – đủ định biên tối thiểu', 'success'],
        'partial' => ['Hợp lệ nhưng còn thiếu người', 'warning'], 'infeasible' => ['Không thể đủ định biên tối thiểu', 'danger'],
        'timeout' => ['Hết thời gian', 'danger'], 'error' => ['Lỗi', 'danger'], 'applied' => ['Đã áp dụng vào lịch', 'primary'],
        'discarded' => ['Đã bỏ qua', 'secondary'], 'stale' => ['Đã cũ – lịch đã thay đổi sau lần chạy', 'secondary'],
        'valid' => ['Lịch hiện tại hợp lệ', 'success'], 'has_warnings' => ['Lịch hiện tại có cảnh báo', 'warning'], 'invalid' => ['Lịch hiện tại vi phạm ràng buộc', 'danger'],
    ];
    [$statusLabel, $statusColor] = $statusLabels[$run->status] ?? [$run->status, 'secondary'];
    $unfilled = $proposal['unfilled_requirements'] ?? [];
    $hard = $proposal['hard_constraint_violations'] ?? [];
    $workload = collect($proposal['employee_workload'] ?? [])->sortByDesc('hours')->values();
    $objective = $run->metrics['objective'] ?? [];
    $fairness = $run->metrics['fairness'] ?? [];
    $reasonLabels = [
        'approved_off' => 'Nghỉ đã duyệt', 'locked' => 'Ô đã khóa', 'busy_elsewhere' => 'Đang làm nơi khác',
        'blocked' => 'Vướng quy tắc', 'gender_missing' => 'Chưa khai báo giới tính',
    ];
@endphp

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="text-muted small mb-1">
                        Lần chạy #{{ $run->id }} · {{ ['generate' => 'Tạo lịch', 'reoptimize' => 'Tối ưu lại', 'validate' => 'Kiểm tra lịch'][$run->type] ?? $run->type }}
                        · {{ $run->scope_from->format('d/m/Y') }} – {{ $run->scope_to->format('d/m/Y') }}
                        · {{ $run->created_at->format('d/m H:i') }}
                    </div>
                    <h2 class="mb-2"><span class="badge bg-{{ $statusColor }} text-white fs-4">{{ $statusLabel }}</span></h2>
                    @foreach (explode("\n", (string) $run->explanation) as $line)
                        @if (trim($line))
                            <p class="mb-1">{{ $line }}</p>
                        @endif
                    @endforeach
                </div>
                @if ($canApply)
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('roster.auto.discard', $run->id) }}">
                            @csrf
                            <button class="btn">Bỏ qua</button>
                        </form>
                        <form method="POST" action="{{ route('roster.auto.apply', $run->id) }}" onsubmit="return confirm('Áp dụng {{ count($diff) }} thay đổi vào lịch (trạng thái nháp)? Ô đã khóa và nghỉ phép không bị đổi.')">
                            @csrf
                            <button class="btn btn-primary"><x-core::icon name="ti ti-check" /> Áp dụng vào lịch (nháp)</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row row-cards mb-3">
        @foreach ([
            ['Vi phạm ràng buộc cứng', count($hard), count($hard) ? 'text-danger' : 'text-success'],
            ['Ô thiếu tối thiểu', $run->metrics['unfilled_min'] ?? 0, ($run->metrics['unfilled_min'] ?? 0) ? 'text-danger' : 'text-success'],
            ['Ô thiếu mục tiêu', $run->metrics['unfilled_target'] ?? 0, ($run->metrics['unfilled_target'] ?? 0) ? 'text-warning' : 'text-success'],
            ['OFF lẻ (2 ngày không liền nhau)', $offSplit = collect($workload)->sum('off_split'), $offSplit ? 'text-danger' : 'text-success'],
            ['Lượt OFF thứ Bảy', $objective['busy_day_off'] ?? '—', ''],
            ['Ca nặng (nghỉ < 12h)', $objective['heavy_transitions'] ?? 0, ''],
            ['Giờ làm: lệch chuẩn', isset($fairness['hours_stddev']) ? $fairness['hours_stddev'] . 'h' : '—', ''],
            ['Ô thay đổi', $run->type === 'validate' ? '—' : count($diff), ''],
        ] as [$label, $value, $class])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card card-sm"><div class="card-body">
                    <div class="text-muted small">{{ $label }}</div>
                    <div class="h2 mb-0 {{ $class }}">{{ $value }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    @if ($hard)
        <div class="card mb-3 border-danger">
            <div class="card-header"><h3 class="card-title text-danger">Lỗi nghiêm trọng – vi phạm ràng buộc cứng</h3></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <thead><tr><th>Ngày</th><th>Nhân viên</th><th>Outlet / ca</th><th>Nguyên nhân</th></tr></thead>
                    <tbody>
                        @foreach ($hard as $v)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($v['date'])->format('d/m') }}</td>
                                <td>{{ $v['employee_name'] }}</td>
                                <td>{{ $v['outlet_name'] }} {{ $v['shift_code'] }}</td>
                                <td>{{ $v['message'] }} @if ($v['fixed']) <span class="badge bg-secondary-lt">ô khóa / ngoài phạm vi</span> @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($unfilled)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Thiếu nhân sự – lý do và phương án ({{ count($unfilled) }} ô)</h3></div>
            <div class="list-group list-group-flush">
                @foreach ($unfilled as $u)
                    <details class="list-group-item">
                        <summary class="d-flex flex-wrap align-items-center gap-2" style="cursor: pointer">
                            <span class="badge {{ $u['level'] === 'min' ? 'bg-danger' : 'bg-warning' }} text-white">{{ $u['level'] === 'min' ? 'Dưới tối thiểu' : 'Dưới mục tiêu' }}</span>
                            <strong>{{ \Carbon\Carbon::parse($u['date'])->format('d/m') }} · {{ $u['outlet_name'] }} · {{ $u['shift_code'] }}</strong>
                            <span class="text-muted">
                                có {{ $u['assigned'] }} người (tối thiểu {{ $u['min'] }}, mục tiêu {{ $u['target'] }})
                                @if ($u['min_male'] || $u['target_male']) · nam {{ $u['assigned_male'] }} (tối thiểu {{ $u['min_male'] }}, mục tiêu {{ $u['target_male'] }}) @endif
                                @foreach ($u['missing_positions'] ?? [] as $position => $gap) · <span class="text-danger fw-semibold">thiếu {{ $gap }} {{ $position }}</span> @endforeach
                                · {{ $u['eligible_total'] }} người đủ điều kiện
                            </span>
                            @if ($u['provably_impossible'])
                                <span class="badge bg-danger-lt">Không thể đủ với dữ liệu hiện tại</span>
                            @endif
                        </summary>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="fw-semibold mb-1">Nguyên nhân</div>
                                <ul class="mb-0 small">
                                    @foreach ($reasonLabels as $key => $label)
                                        @if (! empty($u['reasons'][$key]))
                                            <li><strong>{{ $label }}:</strong> {{ implode('; ', array_slice($u['reasons'][$key], 0, 8)) }}@if (count($u['reasons'][$key]) > 8)…@endif</li>
                                        @endif
                                    @endforeach
                                    @if (! $u['eligible_total'])
                                        <li>Không có nhân viên nào được cấu hình đủ điều kiện cho outlet/ca này.</li>
                                    @endif
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <div class="fw-semibold mb-1">Phương án đề xuất (quản lý quyết định)</div>
                                <ul class="mb-0 small">
                                    @foreach ($u['proposals'] as $p)
                                        <li><span class="badge bg-secondary-lt">{{ $p['label'] }}</span> {{ $p['text'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($proposal['warnings']))
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title text-warning">Cấp quản lý – lệch ca sáng / chiều ({{ count($proposal['warnings']) }})</h3></div>
            <div class="list-group list-group-flush">
                @foreach ($proposal['warnings'] as $warning)
                    <div class="list-group-item">{{ $warning['message'] }}</div>
                @endforeach
            </div>
            <div class="card-footer small text-muted">
                Thuật toán không đổi OFF đã cho, ca đã khóa hay outlet của quản lý để cân bằng. Nếu cần, điều chỉnh tay trên Bảng xếp ca
                (đổi ca M6 ↔ A2, chuyển OH / M9, điều chuyển outlet theo quyết định của cấp có thẩm quyền).
            </div>
        </div>
    @endif

    @if ($diff)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">So sánh trước / sau ({{ count($diff) }} ô thay đổi)</h3></div>
            <div class="table-responsive" style="max-height: 480px">
                <table class="table card-table table-vcenter table-sm">
                    <thead><tr><th>Ngày</th><th>Nhân viên</th><th>Hiện tại</th><th></th><th>Đề xuất</th><th>Ghi chú</th></tr></thead>
                    <tbody>
                        @foreach ($diff as $d)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($d['date'])->format('d/m') }}</td>
                                <td>{{ $employees[$d['employee_id']]->name ?? '#' . $d['employee_id'] }}</td>
                                <td class="text-muted font-monospace">{{ $d['before'] }}</td>
                                <td>→</td>
                                <td class="fw-semibold font-monospace">{{ $d['after'] }}</td>
                                <td class="small text-muted">{{ $d['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($workload->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Khối lượng & công bằng</h3>
                @if ($fairness)
                    <div class="card-actions text-muted small">
                        Giờ làm: {{ $fairness['hours_min'] }}h – {{ $fairness['hours_max'] }}h (TB {{ $fairness['hours_mean'] }}h)
                        · ca nặng nhiều nhất {{ $fairness['heavy_max_per_employee'] }} lần/người
                    </div>
                @endif
            </div>
            <div class="table-responsive" style="max-height: 480px">
                <table class="table card-table table-vcenter table-sm">
                    <thead>
                        <tr><th>Nhân viên</th><th class="text-center">Ngày làm</th><th class="text-center">OFF</th><th class="text-center">Giờ</th>
                            <th class="text-center">Sáng</th><th class="text-center">Chiều</th><th class="text-center">Đêm</th>
                            <th class="text-center">Ca nặng</th><th class="text-center" title="Số cụm OFF – ít cụm hơn = nghỉ liền nhau hơn">Cụm OFF</th><th class="text-center" title="Ngày OFF lẻ do thuật toán xếp (không tính ngày nhân viên tự xin OFF)">OFF lẻ</th><th class="text-center" title="Số ngày OFF rơi vào thứ Bảy">OFF T7</th><th class="text-center" title="Số ca có (L) hoặc làm trọn ở Lobby">Lobby</th><th class="text-center">Ngoài outlet</th><th class="text-center">Trùng ngày bận</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($workload as $w)
                            <tr>
                                <td>{{ $w['name'] }} <span class="text-muted small">{{ $employees[$w['employee_id']]->outlet->code ?? '' }}</span></td>
                                <td class="text-center">{{ $w['worked_days'] }}</td>
                                <td class="text-center">{{ $w['off_days'] }}</td>
                                <td class="text-center">{{ $w['hours'] }}</td>
                                <td class="text-center">{{ $w['morning'] }}</td>
                                <td class="text-center">{{ $w['afternoon'] }}</td>
                                <td class="text-center">{{ $w['night'] }}</td>
                                <td class="text-center {{ $w['heavy_transitions'] >= 3 ? 'text-warning fw-bold' : '' }}">{{ $w['heavy_transitions'] }}</td>
                                <td class="text-center" title="{{ $w['off_days'] }} ngày OFF thành {{ $w['off_blocks'] ?? '?' }} cụm">{{ $w['off_blocks'] ?? '—' }}</td>
                                <td class="text-center {{ ($w['off_split'] ?? 0) ? 'text-danger fw-bold' : '' }}">{{ $w['off_split'] ?? '—' }}</td>
                                <td class="text-center">{{ $w['busy_off'] ?? '—' }}</td>
                                <td class="text-center">{{ $w['lobby'] ?? '—' }}</td>
                                <td class="text-center">{{ $w['off_home_outlet'] }}</td>
                                <td class="text-center {{ $w['unapproved_request_conflicts'] ? 'text-warning fw-bold' : '' }}">{{ $w['unapproved_request_conflicts'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-body small text-muted">
            Thuật toán: {{ $run->solver }} · {{ $run->duration_ms ? round($run->duration_ms / 1000, 1) . ' giây' : '' }}
            @if (! empty($run->metrics['solver']['iterations'])) · {{ number_format($run->metrics['solver']['iterations']) }} bước thử, {{ number_format($run->metrics['solver']['improved'] ?? 0) }} bước cải thiện @endif
            · seed {{ $run->metrics['solver']['seed'] ?? '—' }}
            · vector mục tiêu {{ json_encode(array_values($objective)) }}
            <br>Thứ tự ưu tiên: vi phạm cứng → điều chuyển làm thiếu nơi nguồn → thiếu tối thiểu (kể cả phủ Lobby) → làm quá định mức tuần → thiếu mục tiêu → ca nặng → công bằng → OFF liền 2–3 ngày → sở thích & ổn định. Ô tiệc (BQ) và ô quản lý khóa luôn được giữ nguyên. Sinh nhật nhân viên được OFF theo quy định.
            Phương án là tốt nhất tìm được trong giới hạn thời gian, không phải lời giải được chứng minh tối ưu.
        </div>
    </div>
@endsection
