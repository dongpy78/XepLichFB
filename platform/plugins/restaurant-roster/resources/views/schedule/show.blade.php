@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @php
        $query = array_filter(['week' => $weekStart->toDateString(), 'outlet_id' => $scope ?: null]);
        $versionKey = $selected ? $selected->version : ($snapshot ? 'draft' : null);
        $exportUrl = fn (string $format, array $extra = []) => route('roster.schedule.export', [...$query, 'version' => $versionKey, 'format' => $format, ...$extra]);
        $badge = ['draft' => 'bg-secondary', 'published' => 'bg-success', 'changed' => 'bg-warning'][$status];
    @endphp

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a class="btn btn-outline-secondary" href="{{ route('roster.schedule.index', [...$query, 'week' => $weekStart->copy()->subWeek()->toDateString()]) }}" title="Tuần trước">‹</a>
                <div class="px-2">
                    <div class="fw-bold fs-3">Tuần {{ $weekStart->isoWeek() }} · {{ $weekStart->format('d/m') }} – {{ $weekEnd->format('d/m/Y') }}</div>
                    <div class="small">Lịch hiện tại: <span class="badge {{ $badge }} text-white">{{ $statusLabels[$status] }}</span></div>
                </div>
                <a class="btn btn-outline-secondary" href="{{ route('roster.schedule.index', [...$query, 'week' => $weekStart->copy()->addWeek()->toDateString()]) }}" title="Tuần sau">›</a>
            </div>
            <a class="btn" href="{{ route('roster.index', $query) }}"><x-core::icon name="ti ti-calendar" /> Về Bảng xếp ca</a>
        </div>
    </div>

    @if ($status === 'changed')
        <div class="alert alert-warning">Lịch trên Bảng xếp ca đã thay đổi sau lần công bố gần nhất – file tải ở đây vẫn là bản đã công bố. Muốn gửi lịch mới: công bố lại trên Bảng xếp ca.</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-3">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Các phiên bản đã công bố</h3></div>
                <div class="list-group list-group-flush">
                    @forelse ($versions as $v)
                        <a class="list-group-item list-group-item-action {{ $selected?->id === $v->id ? 'active' : '' }}" href="{{ route('roster.schedule.index', [...$query, 'version' => $v->version]) }}">
                            <div class="d-flex justify-content-between">
                                <strong>Phiên bản v{{ $v->version }}</strong>
                                @if ($loop->first)<span class="badge bg-success text-white">Mới nhất</span>@endif
                            </div>
                            <div class="small">{{ $v->published_at?->format('d/m/Y H:i') }}{{ $v->published_by_name ? ' · ' . $v->published_by_name : '' }}</div>
                            <div class="small text-muted">{{ $v->employee_count }} nhân viên</div>
                        </a>
                    @empty
                        <div class="list-group-item text-muted">Tuần này chưa công bố lần nào.</div>
                    @endforelse
                    @if ($canDraft)
                        <a class="list-group-item list-group-item-action {{ $versionKey === 'draft' ? 'active' : '' }}" href="{{ route('roster.schedule.index', [...$query, 'version' => 'draft']) }}">
                            <strong>Bản nháp hiện tại</strong>
                            <div class="small">Lịch đang chỉnh sửa – chưa phải lịch chính thức</div>
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            @if (! $snapshot)
                <div class="card"><div class="card-body text-muted">Chưa có lịch đã công bố cho tuần này. Bấm "Công bố lịch tuần" trên Bảng xếp ca.</div></div>
            @else
                <div class="card mb-3">
                    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0">
                                {{ $selected ? 'Lịch chính thức – phiên bản v' . $selected->version : 'BẢN NHÁP – chưa công bố' }}
                            </h3>
                            <div class="small text-muted">{{ $snapshots->period($snapshot) }} · {{ $snapshot['scope_label'] }} · {{ $snapshots->caption($snapshot) }}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-primary" href="{{ $exportUrl('png') }}" data-download><x-core::icon name="ti ti-photo" /> Tải ảnh PNG</a>
                            <a class="btn btn-sm" href="{{ $exportUrl('xlsx') }}" data-download><x-core::icon name="ti ti-file-spreadsheet" /> Tải Excel</a>
                            <a class="btn btn-sm" href="{{ $exportUrl('pdf') }}" data-download><x-core::icon name="ti ti-file-type-pdf" /> Tải PDF</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered card-table mb-0">
                            <thead>
                                <tr>
                                    <th>STT</th><th>Họ và tên</th><th>Chức vụ</th>
                                    @foreach ($snapshot['days'] as $day)
                                        <th class="text-center">{{ $day['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $n = 0;
                                @endphp
                                @foreach ($snapshot['sections'] as $section)
                                    <tr class="table-warning"><td colspan="{{ 3 + count($snapshot['days']) }}" class="fw-bold">{{ mb_strtoupper($section['label']) }}</td></tr>
                                    @foreach ($section['rows'] as $row)
                                        <tr>
                                            <td class="text-center text-muted">{{ ++$n }}</td>
                                            <td class="fw-semibold text-nowrap">{{ $row['name'] }}</td>
                                            <td class="small text-muted text-nowrap">{{ $row['position'] }}</td>
                                            @foreach ($row['cells'] as $k => $cell)
                                                <td class="text-center font-monospace {{ ! empty($row['offs'][$k]) ? 'bg-light text-muted' : 'fw-bold' }}">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" id="png">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title">Ảnh PNG để gửi Zalo</h3>
                        <a class="btn btn-sm btn-primary" href="{{ $exportUrl('png') }}" data-download><x-core::icon name="ti ti-download" /> Tải ảnh</a>
                    </div>
                    <div class="card-body text-center">
                        <img src="{{ $exportUrl('png', ['inline' => 1]) }}" alt="Lịch làm việc tuần" class="img-fluid border rounded" loading="lazy">
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Đang tạo file: khóa nút vài giây, tránh bấm liên tục.
        document.querySelectorAll('[data-download]').forEach(function (link) {
            link.addEventListener('click', function () {
                var label = link.innerHTML;
                link.classList.add('disabled');
                link.innerHTML = 'Đang tạo file…';
                setTimeout(function () { link.classList.remove('disabled'); link.innerHTML = label; }, 4000);
            });
        });
    </script>
@endsection
