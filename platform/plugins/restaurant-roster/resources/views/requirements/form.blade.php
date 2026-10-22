@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $editing = $model->exists;
    $weekdays = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ nhật'];
    $mode = old('apply_mode', $model->date ? 'date' : ($model->weekday ? 'weekday' : 'all'));
    $val = fn (string $field) => old($field, $model->{$field} instanceof \Carbon\CarbonInterface ? $model->{$field}->toDateString() : $model->{$field});
    $time = fn (string $field) => old($field, $model->{$field} ? substr((string) $model->{$field}, 0, 5) : null);
    $groups = old('positions', collect($model->relationLoaded('positions') ? $model->positions : [])->map(fn ($p) => [
        'kind' => $p->kind, 'position_id' => $p->position_id, 'applies' => 1, 'min_staff' => $p->min_staff, 'target_staff' => $p->target_staff, 'note' => $p->note,
    ])->values()->all());
    $err = fn (string $field) => $errors->first($field);
@endphp

@section('content')
    <form method="POST" id="req-form" novalidate
        action="{{ $editing ? route('roster.requirements.edit.update', $model->id) : route('roster.requirements.create.store') }}"
        data-preview-url="{{ route('roster.requirements.preview') }}">
        @csrf
        <input type="hidden" name="id" value="{{ $model->id }}">
        <input type="hidden" name="after" value="">

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Chưa lưu được – kiểm tra các trường được đánh dấu.</strong>
                @if ($err('conflict'))<div class="mt-1">{{ $err('conflict') }}</div>@endif
            </div>
        @endif
        @if ($source)
            <div class="alert alert-info">Sao chép từ định biên #{{ $source->id }} ({{ $source->outlet?->code }} · {{ $source->template?->code }}, {{ $source->scheduleLabel() }}). Đổi ca / ngày áp dụng rồi lưu – bản sao mặc định là nháp.</div>
        @endif

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Thông tin chung</h3></div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label required" for="outlet_id">Outlet</label>
                                <select class="form-select @if ($err('outlet_id')) is-invalid @endif" id="outlet_id" name="outlet_id" required>
                                    <option value="">— Chọn —</option>
                                    @foreach ($outlets as $outlet)
                                        <option value="{{ $outlet->id }}" @selected($val('outlet_id') == $outlet->id)>{{ $outlet->code }} – {{ $outlet->name }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback">{{ $err('outlet_id') ?: 'Chọn outlet.' }}</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label required" for="shift_template_id">Ca</label>
                                <select class="form-select @if ($err('shift_template_id')) is-invalid @endif" id="shift_template_id" name="shift_template_id" required>
                                    <option value="">— Chọn —</option>
                                    @foreach ($templates as $template)
                                        <option value="{{ $template->id }}" @selected($val('shift_template_id') == $template->id)>{{ $template->code }} ({{ $template->time_range }})</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback">{{ $err('shift_template_id') ?: 'Chọn ca.' }}</div>
                            </div>
                        </div>

                        <label class="form-label mt-3">Áp dụng</label>
                        <div class="btn-group w-100 mb-2" role="group">
                            @foreach (['all' => 'Mọi ngày', 'weekday' => 'Theo thứ', 'date' => 'Ngày cụ thể'] as $value => $label)
                                <input type="radio" class="btn-check" name="apply_mode" id="mode-{{ $value }}" value="{{ $value }}" @checked($mode === $value)>
                                <label class="btn btn-sm" for="mode-{{ $value }}">{{ $label }}</label>
                            @endforeach
                        </div>
                        <div data-mode="weekday" class="mb-2">
                            <select class="form-select @if ($err('weekday')) is-invalid @endif" name="weekday">
                                @foreach ($weekdays as $value => $label)
                                    <option value="{{ $value }}" @selected($val('weekday') == $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback">{{ $err('weekday') }}</div>
                        </div>
                        <div data-mode="date" class="mb-2">
                            <input type="date" class="form-control @if ($err('date')) is-invalid @endif" name="date" value="{{ $val('date') }}">
                            <div class="invalid-feedback">{{ $err('date') ?: 'Chọn ngày.' }}</div>
                        </div>
                        <div class="form-hint mb-3">Ưu tiên khi nhiều dòng cùng khớp: ngày cụ thể &gt; theo thứ &gt; mọi ngày; cùng mức thì dòng có điều kiện tầng trên khớp được ưu tiên. Hai dòng cùng mức mà khác số là xung đột – hệ thống không cho lưu.</div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="effective_from">Hiệu lực từ</label>
                                <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ $val('effective_from') }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="effective_to">Đến</label>
                                <input type="date" class="form-control @if ($err('effective_to')) is-invalid @endif" id="effective_to" name="effective_to" value="{{ $val('effective_to') }}">
                                <div class="invalid-feedback">{{ $err('effective_to') ?: 'Phải sau ngày bắt đầu.' }}</div>
                            </div>
                            <div class="col-12 form-hint">Để trống = không giới hạn. Cấu hình mới bắt đầu ngày X: đặt "Đến" của dòng cũ là ngày X − 1.</div>
                        </div>

                        <label class="form-label mt-3" for="upper_floor">Trạng thái khu vực (tầng trên Villa)</label>
                        <select class="form-select" id="upper_floor" name="upper_floor">
                            <option value="" @selected($val('upper_floor') === null || $val('upper_floor') === '')>Áp dụng mọi trường hợp</option>
                            <option value="0" @selected($val('upper_floor') === false || $val('upper_floor') === '0')>Chỉ khi KHÔNG mở tầng trên</option>
                            <option value="1" @selected($val('upper_floor') === true || $val('upper_floor') === '1')>Chỉ khi MỞ tầng trên</option>
                        </select>
                        <div class="form-hint">Mở / đóng tầng trên, đóng cửa outlet theo ngày: màn hình "Ngày đặc biệt".</div>

                        <label class="form-label mt-3">Khung đếm (tùy chọn)</label>
                        <div class="row g-2">
                            <div class="col-6"><input type="time" class="form-control" name="count_start_time" value="{{ $time('count_start_time') }}" aria-label="Khung đếm từ"></div>
                            <div class="col-6">
                                <input type="time" class="form-control @if ($err('count_end_time')) is-invalid @endif" name="count_end_time" value="{{ $time('count_end_time') }}" aria-label="Khung đếm đến">
                                <div class="invalid-feedback">{{ $err('count_end_time') }}</div>
                            </div>
                        </div>
                        <div class="form-hint">Để trống: người hỗ trợ Lobby (L) không được tính vào outlet trong ca. Đặt khung (vd M6 06:00–09:00 setup buffet): người (L) có mặt ở outlet cả khung này vẫn được tính – vd M6(L) lên Lobby 09–12h.</div>

                        <div class="row g-2 mt-2">
                            <div class="col-6">
                                <label class="form-label" for="status">Trạng thái cấu hình</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" @selected($val('status') === 'active')>Đang áp dụng</option>
                                    <option value="draft" @selected($val('status') === 'draft')>Nháp (chưa dùng)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="is_confirmed">Xác nhận</label>
                                <select class="form-select" id="is_confirmed" name="is_confirmed">
                                    <option value="1" @selected((bool) $val('is_confirmed'))>Quản lý đã xác nhận</option>
                                    <option value="0" @selected(! $val('is_confirmed'))>Gợi ý – cần kiểm tra</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-hint">Chỉ dòng "Đang áp dụng" được thuật toán dùng. Nháp được phép thiếu số và không bao giờ bị coi là định biên 0.</div>

                        <label class="form-label mt-3" for="note">Ghi chú nghiệp vụ</label>
                        <input type="text" class="form-control" id="note" name="note" maxlength="255" value="{{ $val('note') }}">
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Định biên theo nhóm nhân sự</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table" id="req-groups">
                            <thead><tr><th>Nhóm nhân sự</th><th style="width: 110px">Tối thiểu</th><th style="width: 110px">Mục tiêu</th><th></th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><strong>Tổng số người</strong><div class="text-muted small">Người làm trực tiếp tại outlet trong ca</div></td>
                                    @foreach (['min_staff', 'target_staff'] as $field)
                                        <td>
                                            <input type="number" min="0" max="50" class="form-control form-control-sm @if ($err($field)) is-invalid @endif" name="{{ $field }}" value="{{ $val($field) }}" data-pair="total" data-role="{{ str_starts_with($field, 'min') ? 'min' : 'target' }}">
                                            <div class="invalid-feedback">{{ $err($field) }}</div>
                                        </td>
                                    @endforeach
                                    <td>
                                        <input type="number" min="0" max="50" class="form-control form-control-sm @if ($err('max_staff')) is-invalid @endif" name="max_staff" value="{{ $val('max_staff') }}" placeholder="Tối đa" title="Tối đa (trống = mục tiêu + phần linh hoạt cấu hình)">
                                        <div class="invalid-feedback">{{ $err('max_staff') }}</div>
                                    </td>
                                </tr>
                                @foreach (['male' => ['Nam', 'Mọi vị trí (gồm cả quản lý)'], 'female' => ['Nữ waiter', 'Nữ ở vị trí Waiter, không gồm quản lý; người hỗ trợ Lobby / Kid không tính – dùng cân đối M6/A2']] as $key => [$label, $hint])
                                    @php $applies = old("applies_{$key}", $model->{"min_{$key}"} !== null ? 1 : 0); @endphp
                                    <tr data-group-row>
                                        <td>
                                            <label class="form-check mb-0">
                                                <input type="checkbox" class="form-check-input" name="applies_{{ $key }}" value="1" data-applies @checked($applies)>
                                                <span class="form-check-label"><strong>{{ $label }}</strong></span>
                                            </label>
                                            <div class="text-muted small">{{ $hint }}</div>
                                        </td>
                                        @foreach (['min', 'target'] as $role)
                                            <td>
                                                <input type="number" min="0" max="50" class="form-control form-control-sm @if ($err("{$role}_{$key}")) is-invalid @endif" name="{{ $role }}_{{ $key }}" value="{{ $val("{$role}_{$key}") }}" data-pair="{{ $key }}" data-role="{{ $role }}">
                                                <div class="invalid-feedback">{{ $err("{$role}_{$key}") }}</div>
                                            </td>
                                        @endforeach
                                        <td class="text-muted small">Bỏ chọn = không áp dụng</td>
                                    </tr>
                                @endforeach
                                @foreach ($groups as $k => $group)
                                    @include('plugins/restaurant-roster::requirements.partials.group-row', ['k' => $k, 'group' => $group])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 justify-content-between">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm" data-add-group="position"><x-core::icon name="ti ti-plus" /> Thêm vị trí</button>
                            <button type="button" class="btn btn-sm" data-add-group="managers"><x-core::icon name="ti ti-plus" /> Thêm cấp quản lý</button>
                        </div>
                        <span class="text-muted small align-self-center">Room Service, Kid-Deline… là outlet riêng: chọn outlet đó ở "Thông tin chung".</span>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <label class="form-label" for="reason">Lý do thay đổi (lưu vào lịch sử)</label>
                        <input type="text" class="form-control mb-3" id="reason" name="reason" maxlength="500" value="{{ old('reason') }}" placeholder="VD: Mùa cao điểm, mở thêm tầng trên">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" data-preview><x-core::icon name="ti ti-eye-check" /> Xem trước ảnh hưởng &amp; lưu</button>
                            <button type="submit" class="btn" data-save-draft><x-core::icon name="ti ti-device-floppy" /> Lưu nháp</button>
                            <a href="{{ route('roster.requirements.index') }}" class="btn btn-link">Hủy</a>
                            @if ($editing)
                                <a href="{{ route('roster.requirements.history', $model->id) }}" class="btn btn-link ms-auto"><x-core::icon name="ti ti-history" /> Lịch sử</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <template id="req-group-template">
        @include('plugins/restaurant-roster::requirements.partials.group-row', ['k' => '__K__', 'group' => ['kind' => '__KIND__', 'position_id' => null, 'applies' => 1, 'min_staff' => null, 'target_staff' => null, 'note' => null]])
    </template>

    <div class="modal fade" id="req-preview-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Ảnh hưởng của thay đổi</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body" id="req-preview-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-bs-dismiss="modal">Quay lại sửa</button>
                    <button type="button" class="btn btn-outline-primary" data-confirm-save="reoptimize">Lưu &amp; mở Tối ưu lại</button>
                    <button type="button" class="btn btn-primary" data-confirm-save="">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('req-form');
            var positions = @json($positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'manager' => (bool) $p->is_manager])->values());
            var counter = {{ count($groups) }};

            var syncMode = function () {
                var mode = form.querySelector('[name=apply_mode]:checked').value;
                form.querySelectorAll('[data-mode]').forEach(function (el) { el.classList.toggle('d-none', el.dataset.mode !== mode); });
            };

            var syncApplies = function () {
                form.querySelectorAll('[data-group-row]').forEach(function (row) {
                    var box = row.querySelector('[data-applies]');
                    row.querySelectorAll('input[type=number], select').forEach(function (input) { input.disabled = box && ! box.checked; });
                });
            };

            // Kiểm tra ngay trên trang (giống backend): không âm, tối thiểu ≤ mục tiêu, nhóm áp dụng phải có tối thiểu.
            var check = function (draft) {
                var ok = true;
                form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                var fail = function (el, message) { el.classList.add('is-invalid'); var fb = el.parentNode.querySelector('.invalid-feedback'); if (fb) fb.textContent = message; ok = false; };

                ['outlet_id', 'shift_template_id'].forEach(function (name) { var el = form.elements[name]; if (! el.value) fail(el, 'Bắt buộc.'); });

                var mode = form.querySelector('[name=apply_mode]:checked').value;
                if (mode === 'date' && ! form.elements.date.value) fail(form.elements.date, 'Chọn ngày.');

                var from = form.elements.effective_from.value, to = form.elements.effective_to.value;
                if (from && to && from > to) fail(form.elements.effective_to, 'Phải sau ngày bắt đầu hiệu lực.');

                var cs = form.elements.count_start_time.value, ce = form.elements.count_end_time.value;
                if ((cs && ! ce) || (! cs && ce)) fail(form.elements.count_end_time, 'Cần cả giờ bắt đầu và kết thúc.');
                else if (cs && ce && cs >= ce) fail(form.elements.count_end_time, 'Giờ kết thúc phải sau giờ bắt đầu.');

                var pairs = {};
                form.querySelectorAll('[data-pair]').forEach(function (input) {
                    if (input.disabled) return;
                    (pairs[input.dataset.pair] = pairs[input.dataset.pair] || {})[input.dataset.role] = input;
                    if (input.value !== '' && Number(input.value) < 0) fail(input, 'Không được âm.');
                });

                Object.keys(pairs).forEach(function (key) {
                    var min = pairs[key].min, target = pairs[key].target;
                    if (! draft && min && min.value === '' && (key === 'total' || min.closest('[data-group-row]'))) fail(min, key === 'total' ? 'Nhập số người tối thiểu (hoặc lưu nháp).' : 'Nhập tối thiểu hoặc bỏ chọn "áp dụng".');
                    if (min && target && min.value !== '' && target.value !== '' && Number(min.value) > Number(target.value)) fail(target, 'Mục tiêu không được nhỏ hơn tối thiểu.');
                });

                return ok;
            };

            form.addEventListener('change', function (event) {
                if (event.target.name === 'apply_mode') syncMode();
                if (event.target.matches('[data-applies]')) syncApplies();
            });

            form.querySelectorAll('[data-add-group]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var html = document.getElementById('req-group-template').innerHTML.replace(/__K__/g, 'n' + (counter++)).replace(/__KIND__/g, button.dataset.addGroup);
                    form.querySelector('#req-groups tbody').insertAdjacentHTML('beforeend', html);
                    syncApplies();
                });
            });

            form.addEventListener('click', function (event) {
                if (event.target.closest('[data-remove-group]')) event.target.closest('tr').remove();
            });

            form.querySelector('[data-save-draft]').addEventListener('click', function (event) {
                form.elements.status.value = 'draft';
                if (! check(true)) event.preventDefault();
            });

            var modal = document.getElementById('req-preview-modal');
            var body = document.getElementById('req-preview-body');
            var label = { ok: 'Đủ', short_target: 'Dưới mục tiêu', short_min: 'Thiếu tối thiểu', none: 'Không có định biên' };
            var fmt = function (r) { return r ? r.min + '/' + r.target + (r.min_male ? ' ♂' + r.min_male : '') + (r.min_female ? ' ♀' + r.min_female : '') : '—'; };

            form.querySelector('[data-preview]').addEventListener('click', function (event) {
                var button = event.currentTarget;
                if (! check(form.elements.status.value === 'draft')) return;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang tính ảnh hưởng…';
                syncApplies();
                var data = new FormData(form);

                fetch(form.dataset.previewUrl, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json().then(function (json) { return { status: response.status, json: json }; }); })
                    .then(function (res) {
                        if (res.status === 422) {
                            var errors = res.json.errors || {};
                            Object.keys(errors).forEach(function (key) {
                                var name = key.replace(/^positions\.([^.]+)\.(.+)$/, 'positions[$1][$2]');
                                var el = form.elements[name];
                                var message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
                                if (el && el.classList) { el.classList.add('is-invalid'); var fb = el.parentNode.querySelector('.invalid-feedback'); if (fb) fb.textContent = message; }
                                else Botble.showError(message);
                            });
                            Botble.showError(res.json.message || 'Dữ liệu chưa hợp lệ.');
                            return;
                        }

                        var d = res.json.data;
                        var html = '<p>Trong ' + d.from.split('-').reverse().join('/') + ' – ' + d.to.split('-').reverse().join('/') + ': <strong>' + d.changed_days + '</strong> ngày đổi định biên, '
                            + '<strong>' + d.scheduled_days + '</strong> ngày đã có lịch, <strong>' + d.published_days + '</strong> ngày đã công bố.</p>';
                        if (d.newly_short.length) html += '<div class="alert alert-warning">' + d.newly_short.length + ' ngày đã có lịch sẽ <strong>thiếu tối thiểu</strong> theo định biên mới – lịch không tự đổi; cân nhắc "Lưu & mở Tối ưu lại" hoặc sửa tay.</div>';
                        if (! d.changed_days) html += '<div class="alert alert-success">Không ngày nào trong ' + {{ \Botble\RestaurantRoster\Services\StaffingRequirementService::IMPACT_WEEKS }} + ' tuần tới bị đổi định biên.</div>';
                        if (d.rows.length) {
                            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Ngày</th><th>Đã xếp (người / ♂ / ♀ waiter)</th><th>Cũ</th><th>Mới</th><th>Tình trạng mới</th></tr></thead><tbody>';
                            d.rows.forEach(function (r) {
                                html += '<tr><td>' + r.date.split('-').reverse().join('/') + (r.published ? ' <span class="badge bg-primary-lt">đã công bố</span>' : '') + '</td>'
                                    + '<td class="font-monospace">' + (r.scheduled ? r.actual.total + ' / ' + r.actual.male + ' / ' + r.actual.female : '<span class="text-muted">chưa xếp</span>') + '</td>'
                                    + '<td class="font-monospace">' + fmt(r.old) + '</td><td class="font-monospace">' + fmt(r.new) + '</td>'
                                    + '<td>' + (r.scheduled ? label[r.new_status] : '—') + '</td></tr>';
                            });
                            html += '</tbody></table></div>';
                        }
                        body.innerHTML = html;
                        $(modal).modal('show');
                    })
                    .catch(function () { Botble.showError('Không tính được ảnh hưởng – kiểm tra kết nối rồi thử lại.'); })
                    .finally(function () { button.disabled = false; button.innerHTML = 'Xem trước ảnh hưởng &amp; lưu'; });
            });

            modal.querySelectorAll('[data-confirm-save]').forEach(function (button) {
                button.addEventListener('click', function () {
                    form.elements.after.value = button.dataset.confirmSave;
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang lưu…';
                    syncApplies();
                    form.querySelectorAll('[data-group-row] input:disabled').forEach(function (input) { input.disabled = false; if (input.type === 'number') input.value = ''; });
                    form.submit();
                });
            });

            // Ô nhóm không áp dụng: gửi trống (không áp dụng), không phải 0.
            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-group-row]').forEach(function (row) {
                    var box = row.querySelector('[data-applies]');
                    if (box && ! box.checked) row.querySelectorAll('input[type=number]').forEach(function (input) { input.disabled = false; input.value = ''; });
                });
            });

            syncMode();
            syncApplies();
        })();
    </script>
@endsection
