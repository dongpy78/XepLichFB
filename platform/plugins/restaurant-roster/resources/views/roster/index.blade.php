@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div
        id="roster-app"
        data-grid-url="{{ route('roster.grid') }}"
        data-assign-url="{{ route('roster.assign') }}"
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
                            <button type="button" class="btn btn-outline-secondary" data-off-select title="Bấm vào các ô cần ghi (nhiều người, nhiều ngày; giữ Shift để chọn liền nhiều ngày của một người), chọn OFF hoặc ca nhân viên xin (M6 / A2), rồi bấm Ghi & khóa">
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

        <div id="roster-undo" class="alert alert-warning d-none d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <x-core::icon name="ti ti-eraser" />
                Đã xóa lịch của <strong id="roster-undo-label"></strong> – xếp tay lại các ô trống, hoặc hoàn tác nếu xóa nhầm.
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-warning" data-roster-undo><x-core::icon name="ti ti-arrow-back-up" /> Hoàn tác</button>
                <button type="button" class="btn btn-sm" data-roster-undo-dismiss>Đóng</button>
            </div>
        </div>

        <div id="roster-grid">
            @include('plugins/restaurant-roster::roster.grid')
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
        <div class="modal fade" id="roster-cell-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="roster-cell-title">Xếp ca</h5>
                            <div class="text-muted small" id="roster-cell-subtitle"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                    </div>
                    <div class="modal-body">
                        <div class="roster-cell-warnings alert alert-warning d-none" id="roster-cell-warnings"></div>

                        <div class="alert alert-secondary d-none" id="roster-cell-recorded-off">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <span><x-core::icon name="ti ti-lock" /> OFF quản lý đã cho – ô đang khóa, thuật toán không đổi. Thiếu người thì gỡ OFF rồi xếp ca.</span>
                                @if (Auth::user()->hasPermission('roster.approve'))
                                    <button type="button" class="btn btn-sm btn-warning" data-off-remove data-confirm="Gỡ OFF ngày này để xếp ca cho nhân viên đi làm?">Gỡ OFF</button>
                                @endif
                            </div>
                        </div>

                        <div class="alert alert-warning d-none" id="roster-cell-review">
                            <div class="mb-2"><x-core::icon name="ti ti-alert-circle" /> <span id="roster-cell-review-text"></span></div>
                            @if (Auth::user()->hasPermission('roster.approve'))
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm" data-review="keep">Giữ ca, không cho OFF</button>
                                    <button type="button" class="btn btn-sm btn-warning" data-review="off">Cho OFF (thay ca này)</button>
                                </div>
                            @endif
                        </div>

                        <div class="alert alert-info d-none" id="roster-cell-ot">
                            <div class="roster-ot-actions">
                                <span>Tăng ca <strong id="roster-cell-ot-hours"></strong> – <span id="roster-cell-ot-status"></span></span>
                                @if (Auth::user()->hasPermission('roster.approve'))
                                    <button type="button" class="btn btn-sm btn-success" data-ot-status="approved">Duyệt tăng ca</button>
                                    <button type="button" class="btn btn-sm" data-ot-status="rejected">Từ chối</button>
                                @endif
                            </div>
                        </div>

                        <label class="form-label fw-semibold" for="roster-cell-notation">Nhập nhanh ký hiệu</label>
                        <div class="input-group mb-1">
                            <input type="text" class="form-control font-monospace text-uppercase" id="roster-cell-notation" maxlength="40" placeholder="VD: M6, A2(L)(BQ), M6(K)+3, OFF, AL" autocomplete="off">
                            <button type="button" class="btn btn-primary" data-assign="notation">Lưu</button>
                        </div>
                        <div class="form-hint mb-3">Gõ giống bảng Excel rồi nhấn Enter. Hoặc chọn bên dưới.</div>

                        <div class="mb-2 fw-semibold">Ký hiệu phụ & tăng ca <span class="text-muted fw-normal small">(chọn trước khi bấm ca)</span></div>
                        <div class="roster-tag-picker mb-2">
                            @foreach ($shiftTags as $code => $tag)
                                <label class="roster-tag-toggle" title="{{ $tag['description'] }}">
                                    <input type="checkbox" class="roster-tag-input" value="{{ $code }}">
                                    <span><strong class="font-monospace">{{ $code }}</strong> {{ $tag['label'] !== $code ? $tag['label'] : '' }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="input-group input-group-sm mb-3 roster-ot-input">
                            <span class="input-group-text">Tăng ca sau ca</span>
                            <input type="number" class="form-control" id="roster-cell-overtime" min="0" max="12" step="0.5" placeholder="0">
                            <span class="input-group-text">giờ</span>
                        </div>

                        <div class="mb-2 fw-semibold">Chọn ca làm việc</div>
                        <div class="roster-picker">
                            @forelse ($templates as $template)
                                <button type="button" class="roster-picker__item" data-assign="shift" data-template="{{ $template->id }}">
                                    <span class="roster-chip roster-chip--{{ $template->color }}">{{ $template->code }}</span>
                                    <span class="roster-picker__name">{{ $template->name }}</span>
                                    <span class="roster-picker__time font-monospace">{{ $template->time_range }} · {{ number_format($template->hours, 1) }}h</span>
                                </button>
                            @empty
                                <div class="text-muted">Chưa có mẫu ca. <a href="{{ route('roster.shift-templates.create') }}">Tạo mẫu ca</a></div>
                            @endforelse
                        </div>

                        @if (Auth::user()->hasPermission('roster.approve'))
                            @php($requestCodes = array_map('strtoupper', (array) config('plugins.restaurant-roster.general.scheduling.request_shift_codes', ['M6', 'A2'])))
                            <div class="mt-3 mb-2 fw-semibold">Nhân viên xin ca (khóa)</div>
                            <div class="roster-picker roster-picker--inline">
                                @foreach ($templates->filter(fn ($t) => in_array(strtoupper((string) $t->code), $requestCodes, true)) as $template)
                                    <button type="button" class="roster-picker__item" data-shift-request="{{ $template->id }}" title="Nhân viên xin ca {{ $template->code }} (nhắn trong nhóm): ghi ca và khóa – thuật toán không đổi">
                                        <span class="roster-chip"><x-core::icon name="ti ti-lock" /> {{ $template->code }}</span>
                                        <span class="roster-picker__name">Xin ca {{ $template->code }} (khóa)</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-3 mb-2 fw-semibold">Nghỉ</div>
                        <div class="roster-picker roster-picker--inline">
                            @if (Auth::user()->hasPermission('roster.approve'))
                                <button type="button" class="roster-picker__item" data-off-quick title="Nhân viên xin OFF (nhắn Zalo): ghi OFF và khóa – thuật toán không đổi">
                                    <span class="roster-chip roster-chip--off"><x-core::icon name="ti ti-lock" /> OFF</span>
                                    <span class="roster-picker__name">Nhân viên xin OFF (khóa)</span>
                                </button>
                            @endif
                            <button type="button" class="roster-picker__item" data-assign="off">
                                <span class="roster-chip roster-chip--off">OFF</span>
                                <span class="roster-picker__name">Nghỉ tuần (thuật toán có thể đổi)</span>
                            </button>
                            @foreach ($leaveTypes as $code => $label)
                                <button type="button" class="roster-picker__item" data-assign="leave" data-leave="{{ $code }}">
                                    <span class="roster-chip roster-chip--leave">{{ $code }}</span>
                                    <span class="roster-picker__name">{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="mt-3">
                            <label class="form-label" for="roster-cell-note">Ghi chú (tùy chọn)</label>
                            <input type="text" class="form-control" id="roster-cell-note" maxlength="255" placeholder="VD: Hỗ trợ tiệc Gala, trực quầy...">
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-danger" data-assign="clear">
                                <x-core::icon name="ti ti-trash" /> Xóa ca
                            </button>
                            <button type="button" class="btn" id="roster-cell-lock" title="Ô đã khóa được thuật toán giữ nguyên khi tạo lịch / tối ưu lại">
                                <x-core::icon name="ti ti-lock" /> <span>Khóa ô</span>
                            </button>
                        </div>
                        <button type="button" class="btn" data-bs-dismiss="modal">Đóng</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
