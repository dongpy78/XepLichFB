@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div
        id="roster-app"
        data-grid-url="{{ route('roster.grid') }}"
        data-assign-url="{{ route('roster.assign') }}"
        data-assign-undo-url="{{ route('roster.assign-undo') }}"
        data-copy-url="{{ route('roster.copy-previous-week') }}"
        data-publish-url="{{ route('roster.publish') }}"
        data-clear-url="{{ route('roster.clear-week') }}"
        data-clear-cells-url="{{ route('roster.clear-cells') }}"
        data-clear-undo-url="{{ route('roster.clear-undo') }}"
        data-lock-url="{{ route('roster.lock') }}"
        data-swap-url="{{ route('roster.swap-waiters') }}"
        data-overtime-url="{{ route('roster.overtime') }}"
        data-off-remove-url="{{ route('roster.off-remove') }}"
        data-review-url="{{ route('roster.review') }}"
        data-off-record-url="{{ route('roster.off-record') }}"
        data-shift-request-url="{{ route('roster.shift-request') }}"
        data-grid-merge-url="{{ route('roster.grid-merge') }}"
        data-outlet-status-url="{{ route('roster.outlet-status') }}"
        data-publish-preview-url="{{ route('roster.publish-preview') }}"
        data-week="{{ $grid['weekStart']->toDateString() }}"
    >
        <div class="card mb-3">
            <div class="card-body roster-toolbar">
                <div class="roster-toolbar__group">
                    <div class="input-group roster-week-nav">
                        <button type="button" class="btn btn-icon" data-roster-week="-1" title="Tuần trước">
                            <x-core::icon name="ti ti-chevron-left" />
                        </button>
                        <span class="input-group-text roster-week-label" id="roster-week-label">
                            <x-core::icon name="ti ti-calendar" class="me-1" /> {{ $grid['weekLabel'] }}
                        </span>
                        <button type="button" class="btn btn-icon" data-roster-week="1" title="Tuần sau">
                            <x-core::icon name="ti ti-chevron-right" />
                        </button>
                    </div>
                    <button type="button" class="btn" data-roster-week="0">Tuần này</button>

                    <select class="form-select roster-outlet-select" id="roster-outlet" aria-label="Nhà hàng">
                        <option value="">Tất cả nhà hàng ({{ $outlets->count() }})</option>
                        @foreach ($outlets as $outlet)
                            <option value="{{ $outlet->id }}" @selected((string) $grid['outletId'] === (string) $outlet->id)>{{ $outlet->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($canManage)
                    <div class="roster-toolbar__group">
                        @if (Auth::user()->hasPermission('roster.auto'))
                            <a href="{{ route('roster.auto.index', ['week' => $grid['weekStart']->toDateString()]) }}" class="btn" data-auto-link data-auto-base="{{ route('roster.auto.index') }}">
                                <x-core::icon name="ti ti-wand" /> Tự động xếp lịch
                            </a>
                        @endif
                        @if ($waiterSwap)
                            <button type="button" class="btn" data-roster-action="swap"
                                data-confirm="Hoán đổi outlet của TOÀN BỘ Waiter: {{ $waiterSwap['a'] }} Waiter {{ $waiterSwap['a_code'] }} → {{ $waiterSwap['b_code'] }} và {{ $waiterSwap['b'] }} Waiter {{ $waiterSwap['b_code'] }} → {{ $waiterSwap['a_code'] }}? Các vị trí khác giữ nguyên, lịch đã xếp không đổi."
                                title="Luân chuyển toàn bộ Waiter giữa Gusto và Villa">
                                <x-core::icon name="ti ti-arrows-exchange" /> Đổi Waiter Gusto ⇄ Villa
                            </button>
                        @endif
                        @if (Auth::user()->hasPermission('roster.approve'))
                            <button type="button" class="btn btn-outline-secondary" data-off-select title="Bấm vào các ô cần ghi (nhiều người, nhiều ngày; giữ Shift để chọn liền nhiều ngày của một người), chọn OFF hoặc ca nhân viên xin (M6 / A2 / N), rồi bấm Ghi & khóa">
                                <x-core::icon name="ti ti-calendar-off" /> Ghi OFF / ca xin
                            </button>
                        @endif
                        <button type="button" class="btn" data-roster-action="copy">
                            <x-core::icon name="ti ti-copy" /> Sao chép tuần trước
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-roster-clear-week
                            title="Xóa toàn bộ lịch tuần đang xem (cả ca đã công bố) để xếp lại từ đầu – hỏi giữ ô đã khóa; hoàn tác được trong 30 phút">
                            <x-core::icon name="ti ti-eraser" /> Xóa lịch tuần
                        </button>
                        <button type="button" class="btn btn-primary" data-roster-action="publish" title="Kiểm tra lỗi, xác nhận rồi công bố – lưu phiên bản lịch chính thức để tải PNG / Excel / PDF">
                            <x-core::icon name="ti ti-send" /> Công bố lịch tuần
                        </button>
                    </div>
                @endif
            </div>
        </div>

        @if ($canManage && Auth::user()->hasPermission('roster.approve'))
            <div id="roster-off-bar" class="roster-off-bar d-none">
                <div class="roster-off-bar__inner">
                    <strong><x-core::icon name="ti ti-calendar-off" /> Ghi OFF / ca xin:</strong>
                    <span id="roster-off-count">Bấm vào các ô cần ghi</span>
                    @php($requestCodes = array_map('strtoupper', (array) config('plugins.restaurant-roster.general.scheduling.request_shift_codes', ['M6', 'A2'])))
                    <select class="form-select form-select-sm" id="roster-off-type" aria-label="Ghi OFF hoặc ca xin">
                        <optgroup label="OFF">
                            @foreach (config('plugins.restaurant-roster.general.availability_types', []) as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Ca nhân viên xin (khóa)">
                            @foreach ($templates->filter(fn ($t) => in_array(strtoupper((string) $t->code), $requestCodes, true)) as $template)
                                <option value="shift:{{ $template->id }}">Xin ca {{ $template->code }} ({{ $template->time_range }})</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <input type="text" class="form-control form-control-sm" id="roster-off-reason" maxlength="255" placeholder="Lý do (tùy chọn)">
                    <button type="button" class="btn btn-sm btn-primary" data-off-save disabled>Ghi & khóa</button>
                    <button type="button" class="btn btn-sm" data-off-cancel>Hủy</button>
                </div>
                <div class="form-hint mt-1">Giữ <kbd>Shift</kbd> rồi bấm để chọn liền nhiều ngày của cùng một người. Ca đã khóa không bị đè – ô sẽ được đánh dấu "cần xem xét".</div>
            </div>
        @endif

        {{-- Hoàn tác: lần xóa lịch (hàng / cột / tuần) hoặc lần sửa ô gần nhất – roster.js điền nội dung. --}}
        <div id="roster-undo" class="alert alert-warning d-none d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <x-core::icon name="ti ti-arrow-back-up" />
                <span id="roster-undo-text"></span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-warning" data-roster-undo><x-core::icon name="ti ti-arrow-back-up" /> Hoàn tác</button>
                <button type="button" class="btn btn-sm" data-roster-undo-dismiss>Đóng</button>
            </div>
        </div>

        {{-- Lọc / tìm trên bảng ngay trên trình duyệt (không tải lại trang); giữ nguyên khi bảng được vẽ lại sau khi sửa ô / đổi tuần. --}}
        <div class="card mb-3 roster-filters" id="roster-filters">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                <div class="input-icon roster-filters__search">
                    <span class="input-icon-addon"><x-core::icon name="ti ti-search" /></span>
                    <input type="search" class="form-control form-control-sm" id="rf-search" placeholder="Tìm nhân viên…" autocomplete="off" aria-label="Tìm nhân viên">
                </div>
                <select class="form-select form-select-sm" id="rf-position" aria-label="Lọc theo vị trí">
                    <option value="">Mọi vị trí</option>
                </select>
                <select class="form-select form-select-sm" id="rf-gender" aria-label="Lọc theo giới tính">
                    <option value="">Nam &amp; nữ</option>
                    <option value="female">Nữ</option>
                    <option value="male">Nam</option>
                </select>
                <select class="form-select form-select-sm" id="rf-code" aria-label="Lọc theo ca" title="Hiện người có ca này trong tuần và làm mờ các ô khác">
                    <option value="">Mọi ca</option>
                </select>
                <label class="form-check form-check-inline mb-0" title="Có ngày làm ở nhà hàng khác outlet chính (Villa ↔ Gusto)">
                    <input class="form-check-input" type="checkbox" id="rf-transfer"> <span class="form-check-label">Điều chuyển</span>
                </label>
                <label class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="rf-warn"> <span class="form-check-label">Có cảnh báo</span>
                </label>
                <label class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="rf-unscheduled"> <span class="form-check-label">Chưa xếp lịch</span>
                </label>
                <span class="small text-muted ms-auto" id="rf-count"></span>
                <button type="button" class="btn btn-sm btn-link d-none" id="rf-reset">Bỏ lọc</button>
            </div>
        </div>

        <div id="roster-grid">
            {!! $gridHtml !!}
        </div>

        <div class="card mt-3">
            <div class="card-body roster-legend">
                <strong class="me-2">Chú giải ca:</strong>
                @forelse ($templates as $template)
                    <span class="roster-legend__item">
                        <span class="roster-chip roster-chip--{{ $template->color }}">{{ $template->code }}</span>
                        {{ $template->name }} <span class="text-muted font-monospace">{{ $template->time_range }}</span>
                    </span>
                @empty
                    <span class="text-muted">Chưa có mẫu ca.</span>
                    @if (Auth::user()->hasPermission('roster.settings'))
                        <a href="{{ route('roster.shift-templates.create') }}">Tạo mẫu ca đầu tiên</a>
                    @endif
                @endforelse
                <span class="w-100"></span>
                <strong class="me-2">Ký hiệu phụ:</strong>
                @foreach ($shiftTags as $code => $tag)
                    <span class="roster-legend__item">
                        <span class="roster-tag roster-tag--{{ strtolower($code) }}">{{ $code }}</span> {{ $tag['description'] }}
                    </span>
                @endforeach
                <span class="roster-legend__item"><span class="roster-ot">+3 OT</span> Tăng ca nối tiếp sau ca</span>
                <span class="w-100"></span>
                <span class="roster-legend__item"><span class="roster-dot roster-dot--draft"></span> Nháp</span>
                <span class="roster-legend__item"><span class="roster-dot roster-dot--published"></span> Đã công bố</span>
                <span class="roster-legend__item"><x-core::icon name="ti ti-alert-triangle" class="text-warning" /> Cảnh báo xung đột</span>
                <span class="roster-legend__item"><span class="roster-mini roster-mini--off is-locked is-recorded-off roster-legend__sample"><span class="roster-mini__code">OFF</span></span> OFF quản lý đã cho (khóa)</span>
                <span class="roster-legend__item"><span class="roster-mini roster-mini--sky is-locked roster-legend__sample"><span class="roster-mini__code">M6</span></span> Ca đã xác nhận / ca nhân viên xin (khóa)</span>
                <span class="roster-legend__item"><span class="roster-mini roster-mini--sand needs-review roster-legend__sample"><span class="roster-mini__code">A2</span></span> Cần xem xét</span>
            </div>
        </div>
    </div>

    @if ($canManage)
        <div class="modal" id="roster-cell-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered roster-cell-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="roster-cell-title">Xếp ca</h5>
                            <div class="text-muted small" id="roster-cell-subtitle"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                    </div>
                    <div class="modal-body">
                        <div class="roster-cell-locked d-none" id="roster-cell-locked-info">
                            <x-core::icon name="ti ti-lock" /> <span id="roster-cell-locked-text">Ca đang khóa</span>
                        </div>

                        <div class="alert alert-warning py-2 d-none" id="roster-cell-review">
                            <div class="mb-2"><span id="roster-cell-review-text"></span></div>
                            @if (Auth::user()->hasPermission('roster.approve'))
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm" data-review="keep">Giữ ca, không cho OFF</button>
                                    <button type="button" class="btn btn-sm btn-warning" data-review="off">Cho OFF</button>
                                </div>
                            @endif
                        </div>

                        <div class="alert alert-info py-2 d-none" id="roster-cell-ot">
                            <div class="roster-ot-actions">
                                <span>Tăng ca <strong id="roster-cell-ot-hours"></strong> – <span id="roster-cell-ot-status"></span></span>
                                @if (Auth::user()->hasPermission('roster.approve'))
                                    <button type="button" class="btn btn-sm btn-success" data-ot-status="approved">Duyệt</button>
                                    <button type="button" class="btn btn-sm" data-ot-status="rejected">Từ chối</button>
                                @endif
                            </div>
                        </div>

                        <input type="text" class="form-control font-monospace mb-3" id="roster-cell-notation" maxlength="40" placeholder="Nhập mã ca hoặc nội dung tùy ý..." aria-label="Mã ca hoặc nội dung" autocomplete="off">

                        <div class="roster-codes" id="roster-cell-codes">
                            @foreach ($popupCodes as $code => $color)
                                <button type="button" class="roster-code roster-chip--{{ $color }}" data-code="{{ $code }}">{{ $code }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="roster-cell-save"><x-core::icon name="ti ti-lock" /> Lưu</button>
                        <button type="button" class="btn d-none" id="roster-cell-unlock" title="Bỏ khóa, giữ nguyên ca">
                            <x-core::icon name="ti ti-lock-open" /> Gỡ khóa
                        </button>
                        <button type="button" class="btn btn-outline-danger d-none" id="roster-cell-remove" data-confirm="Gỡ ca khỏi ô này? Ô sẽ trống.">
                            <x-core::icon name="ti ti-trash" /> Gỡ ca
                        </button>
                        @if (Auth::user()->hasPermission('roster.approve'))
                            <button type="button" class="btn btn-outline-danger d-none" id="roster-cell-request-remove" data-off-remove data-confirm="">
                                <x-core::icon name="ti ti-trash" /> <span>Gỡ ca</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
