@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $snackOn = $activeSnack !== null;
    $lobbyOn = $rules['lobby']['enabled'];
    $status = fn (bool $on) => $on ? '<span class="badge bg-success text-success-fg">Đang bật</span>' : '<span class="badge bg-secondary text-secondary-fg">Đang tắt</span>';
    $enabledCodes = collect($lobbyCodes)->filter(fn ($code) => $lobbyOn && $rules['lobby']['codes'][$code])->map(fn ($code) => "{$code}(L)")->values();
    $disabledCodes = collect($lobbyCodes)->reject(fn ($code) => $lobbyOn && $rules['lobby']['codes'][$code])->map(fn ($code) => "{$code}(L)")->values();
    $snackCodes = collect($snackOptions)->map(fn ($o, $mode) => ['code' => "{$snackShift}({$o['tag']})", 'on' => ($activeSnack['mode'] ?? null) === $mode]);
@endphp

@section('content')
    @if ($pending)
        <div class="alert alert-warning">
            <h4 class="alert-title">Cấu hình mới ảnh hưởng {{ $pending->count() }} ô lịch đang có</h4>
            <p class="mb-2">Các ô này <b>không bị sửa hay xóa</b>. Chúng vẫn giữ nguyên trên Bảng xếp ca và được đánh dấu cảnh báo để bạn tự quyết định. Chạy lại xếp tự động sẽ không tạo thêm ca thuộc nhóm đã tắt.</p>
            @include('plugins/restaurant-roster::rules.partials.affected', ['rows' => $pending])
        </div>
    @endif

    <form method="POST" action="{{ route('roster.rules.update') }}" id="roster-rules-form">
        @csrf
        @if ($pending)
            <input type="hidden" name="confirmed" value="1">
        @endif

        <div class="row row-cards">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header justify-content-between">
                        <h3 class="card-title">A. Ca Snack</h3>
                        {!! $status($snackOn) !!}
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Quầy Snack riêng, rời nhà hàng cả ca (giờ như ca {{ $snackShift }}). Chỉ tự xếp cho <b>Waiter nữ có khu vực sinh sống Cam Ranh</b>. Không đủ người thì báo thiếu, không lấy người khác.</p>
                        <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column flex-sm-row gap-2 mb-3">
                            @foreach (['off' => ['Tắt', 'Không tự xếp ca Snack']] + collect($snackOptions)->map(fn ($o) => [$o['name'], "Tự xếp {$snackShift}({$o['tag']})"])->all() as $mode => [$name, $hint])
                                <label class="form-selectgroup-item flex-fill">
                                    <input type="radio" name="snack_mode" value="{{ $mode }}" class="form-selectgroup-input" @checked($rules['snack']['mode'] === $mode)>
                                    <span class="form-selectgroup-label d-flex flex-column p-3 text-start">
                                        <strong>{{ $name }}</strong>
                                        <small class="text-muted">{{ $hint }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <label class="form-label" for="snack_count">Số Waiter nữ cần mỗi ngày</label>
                        <input type="number" class="form-control w-auto" id="snack_count" name="snack_count" min="1" max="20" value="{{ $rules['snack']['count'] }}" required>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header justify-content-between">
                        <h3 class="card-title">B. Hỗ trợ Lobby/Bar</h3>
                        {!! $status($lobbyOn) !!}
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="lobby_enabled" value="0">
                        <label class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="lobby_enabled" value="1" id="lobby_enabled" @checked($lobbyOn)>
                            <span class="form-check-label"><b>Cho phép tự động phân công nhân viên nhà hàng hỗ trợ Lobby/Bar</b></span>
                        </label>
                        <p class="text-muted small">Tắt: không còn định biên Lobby, thuật toán không tạo ca (L) cho nhân viên nhà hàng. Ca M6, A2, M9 thông thường không đổi.</p>
                        <div class="d-flex flex-wrap gap-3" id="lobby-codes">
                            @foreach ($lobbyCodes as $code)
                                <label class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="lobby_codes[]" value="{{ $code }}" @checked($rules['lobby']['codes'][$code]) @disabled(! $lobbyOn)>
                                    <span class="form-check-label font-monospace">{{ $code }}(L)</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="text-muted small mt-2 mb-0">Ca riêng chỉ được dùng khi cả công tắc tổng và công tắc của ca đó cùng bật.</p>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">C. Ảnh hưởng của cấu hình {{ $pending ? 'mới' : 'hiện tại' }}</h3></div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><div class="text-muted small">Nhà hàng Snack</div><b>{{ $activeSnack['name'] ?? 'Tắt' }}</b></div>
                            <div class="col-md-3"><div class="text-muted small">Snack cần mỗi ngày</div><b>{{ $snackOn ? $activeSnack['count'] . ' Waiter nữ Cam Ranh' : '—' }}</b></div>
                            <div class="col-md-3"><div class="text-muted small">Ca được tự xếp</div>
                                @forelse ([...$snackCodes->where('on', true)->pluck('code'), ...$enabledCodes] as $code)
                                    <span class="badge bg-success-lt font-monospace">{{ $code }}</span>
                                @empty
                                    —
                                @endforelse
                            </div>
                            <div class="col-md-3"><div class="text-muted small">Ca đang tắt</div>
                                @forelse ([...$snackCodes->where('on', false)->pluck('code'), ...$disabledCodes] as $code)
                                    <span class="badge bg-secondary-lt font-monospace">{{ $code }}</span>
                                @empty
                                    —
                                @endforelse
                            </div>
                        </div>
                        @if ($affected->isNotEmpty())
                            <div class="mb-1"><b>{{ $affected->count() }} ô lịch từ hôm nay đang thuộc nhóm ca đã tắt</b> (giữ nguyên, quản lý tự quyết định):</div>
                            @include('plugins/restaurant-roster::rules.partials.affected', ['rows' => $affected])
                        @else
                            <div class="text-muted">Không có ô lịch nào từ hôm nay thuộc nhóm ca đang tắt.</div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $pending ? 'Vẫn lưu cấu hình, giữ nguyên các ô trên' : 'Lưu cấu hình' }}</button>
                @if ($pending)
                    <a href="{{ route('roster.rules.index') }}" class="btn">Hủy</a>
                @endif
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Lịch sử thay đổi</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Thời gian</th><th>Người đổi</th><th>Thay đổi</th></tr></thead>
                            <tbody>
                                @forelse ($revisions as $revision)
                                    <tr>
                                        <td class="text-nowrap">{{ $revision->created_at?->format('d/m/Y H:i') }}</td>
                                        <td>{{ $revision->user_name ?: '—' }}</td>
                                        <td>{{ $revision->summary }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Chưa có thay đổi nào.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
        document.getElementById('lobby_enabled')?.addEventListener('change', function (event) {
            document.querySelectorAll('#lobby-codes input').forEach(function (input) { input.disabled = !event.target.checked })
        })
    </script>
@endsection
