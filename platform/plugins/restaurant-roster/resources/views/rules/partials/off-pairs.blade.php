@php
    $label = fn ($e) => $e ? trim($e->name . ' · ' . ($e->position?->name ?: '—') . ' · ' . ($e->outlet?->code ?: $e->outlet?->name ?: '—')) : '—';
@endphp

<div class="card mt-3" id="off-pairs">
    <div class="card-header justify-content-between">
        <h3 class="card-title">Cặp nghỉ chung (Shared Day Off)</h3>
        <button type="button" class="btn btn-primary btn-sm" data-pair-open data-action="{{ route('roster.off-pairs.store') }}">+ Thêm cặp nghỉ chung</button>
    </div>
    <div class="card-body pb-0 text-muted small">Xếp tự động ưu tiên hai người OFF cùng ngày (không bắt buộc cùng ca, cùng outlet). Không đổi OFF đã duyệt, ô đã khóa hay lịch đã xếp.</div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Nhân viên 1</th><th>Nhân viên 2</th><th>Trạng thái</th><th class="w-1">Thao tác</th></tr></thead>
            <tbody>
                @forelse ($offPairs as $pair)
                    @php($inactiveStaff = ! $pair->employeesActive())
                    <tr>
                        <td>{{ $label($pair->employeeA) }}</td>
                        <td>{{ $label($pair->employeeB) }}</td>
                        <td>
                            @if (! $pair->is_active)
                                <span class="badge bg-secondary text-secondary-fg">Ngừng áp dụng</span>
                            @elseif ($inactiveStaff)
                                <span class="badge bg-warning text-warning-fg" title="Một người đã ngừng hoạt động – thuật toán bỏ qua cặp này">Tạm không áp dụng</span>
                            @else
                                <span class="badge bg-success text-success-fg">Đang áp dụng</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm" data-pair-open data-action="{{ route('roster.off-pairs.update', $pair->getKey()) }}"
                                data-a="{{ $pair->employee_a_id }}" data-b="{{ $pair->employee_b_id }}" data-active="{{ $pair->is_active ? 1 : 0 }}">Chỉnh sửa</button>
                            <form method="POST" action="{{ route('roster.off-pairs.destroy', $pair->getKey()) }}" class="d-inline"
                                onsubmit="return confirm('Xóa liên kết nghỉ chung của hai nhân viên này? Lịch đã xếp giữ nguyên.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Xóa liên kết</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted">Chưa có cặp nghỉ chung nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="off-pair-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="off-pair-form">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="off-pair-title">Cặp nghỉ chung</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                @foreach (['employee_a_id' => 'Nhân viên 1', 'employee_b_id' => 'Nhân viên 2'] as $field => $title)
                    <label class="form-label" for="{{ $field }}">{{ $title }}</label>
                    <input type="search" class="form-control form-control-sm mb-1" placeholder="Tìm tên, chức danh, outlet..." data-pair-filter="{{ $field }}" aria-label="Tìm {{ $title }}">
                    <select class="form-select mb-3" name="{{ $field }}" id="{{ $field }}" size="5" required>
                        @foreach ($pairEmployees as $employee)
                            <option value="{{ $employee->id }}">{{ $label($employee) }}</option>
                        @endforeach
                    </select>
                @endforeach
                <input type="hidden" name="is_active" value="0">
                <label class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="off-pair-active" checked>
                    <span class="form-check-label">Đang áp dụng</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Lưu</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('off-pair-modal')
        var form = document.getElementById('off-pair-form')

        document.querySelectorAll('[data-pair-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                var editing = !! button.dataset.a
                form.action = button.dataset.action
                document.getElementById('off-pair-title').textContent = editing ? 'Chỉnh sửa cặp nghỉ chung' : 'Thêm cặp nghỉ chung'
                form.employee_a_id.value = button.dataset.a || ''
                form.employee_b_id.value = button.dataset.b || ''
                document.getElementById('off-pair-active').checked = ! editing || button.dataset.active === '1'
                form.querySelectorAll('[data-pair-filter]').forEach(function (input) { input.value = ''; input.dispatchEvent(new Event('input')) })
                bootstrap.Modal.getOrCreateInstance(modal).show()
            })
        })

        // Tìm nhân viên: lọc danh sách theo tên / chức danh / outlet (không phân biệt dấu).
        var plain = function (text) { return String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd').toLowerCase() }

        form.querySelectorAll('[data-pair-filter]').forEach(function (input) {
            input.addEventListener('input', function () {
                var query = plain(input.value)
                form[input.dataset.pairFilter].querySelectorAll('option').forEach(function (option) {
                    option.hidden = query !== '' && plain(option.textContent).indexOf(query) === -1
                })
            })
        })

        // Không cho chọn cùng một người ở hai ô.
        form.addEventListener('submit', function (event) {
            if (form.employee_a_id.value && form.employee_a_id.value === form.employee_b_id.value) {
                event.preventDefault()
                alert('Hai nhân viên trong cặp phải khác nhau.')
            }
        })
    })()
</script>
