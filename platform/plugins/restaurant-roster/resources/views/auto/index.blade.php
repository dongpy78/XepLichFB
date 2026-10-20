@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $statusLabels = [
        'running' => ['Đang chạy', 'secondary'], 'feasible' => ['Hợp lệ', 'success'], 'partial' => ['Còn thiếu người', 'warning'],
        'infeasible' => ['Không đủ tối thiểu', 'danger'], 'timeout' => ['Hết thời gian', 'danger'], 'error' => ['Lỗi', 'danger'],
        'applied' => ['Đã áp dụng', 'primary'], 'discarded' => ['Đã bỏ qua', 'secondary'], 'stale' => ['Đã cũ', 'secondary'],
        'valid' => ['Lịch hợp lệ', 'success'], 'has_warnings' => ['Có cảnh báo', 'warning'], 'invalid' => ['Vi phạm ràng buộc', 'danger'],
    ];
    $typeLabels = ['generate' => 'Tạo lịch', 'reoptimize' => 'Tối ưu lại', 'validate' => 'Kiểm tra'];
@endphp

@section('content')
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Chạy thuật toán</h3></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('roster.auto.run') }}" id="roster-auto-form">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Phạm vi nhanh</label>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($presets as $label => [$from, $to])
                                    <button type="button" class="btn btn-sm" data-preset-from="{{ $from }}" data-preset-to="{{ $to }}">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label required" for="auto-from">Từ ngày</label>
                                <input type="date" class="form-control" id="auto-from" name="from" value="{{ reset($presets)[0] }}" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label required" for="auto-to">Đến ngày</label>
                                <input type="date" class="form-control" id="auto-to" name="to" value="{{ reset($presets)[1] }}" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Outlet (để trống = tất cả)</label>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach ($outlets as $outlet)
                                    <label class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="outlet_ids[]" value="{{ $outlet->id }}">
                                        <span class="form-check-label">{{ $outlet->code ?: $outlet->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="type" value="generate" class="btn btn-primary">
                                <x-core::icon name="ti ti-wand" /> Tạo lịch tự động
                            </button>
                            <button type="submit" name="type" value="reoptimize" class="btn">
                                <x-core::icon name="ti ti-refresh" /> Tối ưu lại (giữ ô đã khóa, đổi ít nhất)
                            </button>
                            <button type="submit" name="type" value="validate" class="btn">
                                <x-core::icon name="ti ti-checklist" /> Kiểm tra lịch hiện tại
                            </button>
                        </div>
                        <p class="form-hint mt-3 mb-0">
                            Kết quả chỉ là <strong>phương án</strong>: bạn xem, so sánh rồi mới bấm áp dụng. Lịch áp dụng luôn ở trạng thái nháp,
                            không tự công bố. Ô đã khóa, nghỉ phép và đơn đã duyệt không bao giờ bị thay đổi.
                        </p>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Kiểm tra dữ liệu đầu vào</h3></div>
                <div class="list-group list-group-flush">
                    <a href="{{ route('roster.employees.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span>Nhân viên chưa khai báo giới tính</span>
                        <span class="badge {{ $health['no_gender'] ? 'bg-warning' : 'bg-success' }} text-white">{{ $health['no_gender'] }}</span>
                    </a>
                    <a href="{{ route('roster.skills.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span>Nhân viên chưa có năng lực (không được xếp)</span>
                        <span class="badge {{ $health['no_skills'] ? 'bg-warning' : 'bg-success' }} text-white" title="{{ implode(', ', $health['no_skills']) }}">{{ count($health['no_skills']) }}</span>
                    </a>
                    <a href="{{ route('roster.requirements.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span>Định biên chưa xác nhận</span>
                        <span class="badge {{ $health['unconfirmed_requirements'] ? 'bg-warning' : 'bg-success' }} text-white">{{ $health['unconfirmed_requirements'] }} / {{ $health['requirements'] }}</span>
                    </a>
                    <a href="{{ route('roster.availability.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span>Đơn OFF đang chờ duyệt</span>
                        <span class="badge {{ $health['pending_requests'] ? 'bg-warning' : 'bg-success' }} text-white">{{ $health['pending_requests'] }}</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Các lần chạy gần đây</h3></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr><th>#</th><th>Loại</th><th>Phạm vi</th><th>Kết quả</th><th>Thiếu tối thiểu</th><th>Thời gian</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($runs as $run)
                                @php [$label, $color] = $statusLabels[$run->status] ?? [$run->status, 'secondary']; @endphp
                                <tr>
                                    <td class="text-muted">{{ $run->id }}</td>
                                    <td>{{ $typeLabels[$run->type] ?? $run->type }}</td>
                                    <td>{{ $run->scope_from->format('d/m') }} – {{ $run->scope_to->format('d/m/Y') }}</td>
                                    <td><span class="badge bg-{{ $color }}-lt">{{ $label }}</span></td>
                                    <td>{{ $run->metrics['unfilled_min'] ?? '—' }}</td>
                                    <td class="text-muted small">{{ $run->created_at->format('d/m H:i') }} · {{ $run->duration_ms ? round($run->duration_ms / 1000, 1) . 's' : '—' }}</td>
                                    <td><a href="{{ route('roster.auto.show', $run->id) }}" class="btn btn-sm">Xem</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">Chưa có lần chạy nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-preset-from]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('auto-from').value = button.dataset.presetFrom;
                document.getElementById('auto-to').value = button.dataset.presetTo;
            });
        });

        document.getElementById('roster-auto-form').addEventListener('submit', function (event) {
            var buttons = this.querySelectorAll('button[type=submit]');
            setTimeout(function () { buttons.forEach(function (b) { b.disabled = true; }); }, 0);
            event.submitter && (event.submitter.innerHTML = 'Đang xếp lịch… (tối đa ~30 giây)');
        });
    </script>
@endsection
