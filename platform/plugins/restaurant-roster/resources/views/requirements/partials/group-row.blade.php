{{-- Một nhóm nhân sự theo vị trí (một vị trí trong danh mục, hoặc cấp quản lý) của dòng định biên. --}}
<tr data-group-row>
    <td>
        <input type="hidden" name="positions[{{ $k }}][kind]" value="{{ $group['kind'] }}">
        <div class="d-flex gap-2 align-items-center">
            <input type="checkbox" class="form-check-input mt-0" name="positions[{{ $k }}][applies]" value="1" data-applies @checked($group['applies'] ?? true) title="Áp dụng">
            @if ($group['kind'] === 'managers')
                <strong>Cấp quản lý</strong><span class="text-muted small">(vị trí bật "Cấp quản lý")</span>
                <input type="hidden" name="positions[{{ $k }}][position_id]" value="">
            @else
                <select class="form-select form-select-sm @if ($errors->first("positions.{$k}.position_id")) is-invalid @endif" name="positions[{{ $k }}][position_id]" aria-label="Vị trí">
                    <option value="">— Vị trí —</option>
                    @foreach ($positions as $position)
                        <option value="{{ $position->id }}" @selected(($group['position_id'] ?? null) == $position->id)>{{ $position->name }}{{ $position->is_manager ? ' (quản lý)' : '' }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="invalid-feedback d-block">{{ $errors->first("positions.{$k}.position_id") }}</div>
    </td>
    @foreach (['min_staff', 'target_staff'] as $field)
        <td>
            <input type="number" min="0" max="50" class="form-control form-control-sm @if ($errors->first("positions.{$k}.{$field}")) is-invalid @endif"
                name="positions[{{ $k }}][{{ $field }}]" value="{{ $group[$field] ?? '' }}" data-pair="pos-{{ $k }}" data-role="{{ $field === 'min_staff' ? 'min' : 'target' }}">
            <div class="invalid-feedback">{{ $errors->first("positions.{$k}.{$field}") }}</div>
        </td>
    @endforeach
    <td class="text-end"><button type="button" class="btn btn-sm btn-ghost-danger" data-remove-group title="Bỏ nhóm"><x-core::icon name="ti ti-x" /></button></td>
</tr>
