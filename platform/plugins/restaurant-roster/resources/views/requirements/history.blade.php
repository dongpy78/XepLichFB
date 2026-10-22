@extends(BaseHelper::getAdminMasterLayoutTemplate())

@php
    $actions = ['create' => 'Tạo', 'update' => 'Sửa', 'activate' => 'Áp dụng', 'deactivate' => 'Ngừng hiệu lực', 'delete' => 'Xóa nháp'];
    $labels = [
        'status' => 'Trạng thái', 'weekday' => 'Thứ', 'date' => 'Ngày', 'effective_from' => 'Hiệu lực từ', 'effective_to' => 'Hiệu lực đến',
        'upper_floor' => 'Tầng trên', 'min_staff' => 'Người tối thiểu', 'target_staff' => 'Người mục tiêu', 'max_staff' => 'Tối đa',
        'min_male' => 'Nam tối thiểu', 'target_male' => 'Nam mục tiêu', 'min_female' => 'Nữ waiter tối thiểu', 'target_female' => 'Nữ waiter mục tiêu',
        'count_start_time' => 'Khung đếm từ', 'count_end_time' => 'Khung đếm đến', 'allow_reduce' => 'Cho giảm', 'is_confirmed' => 'Đã xác nhận', 'note' => 'Ghi chú',
        'positions' => 'Nhóm vị trí',
    ];
    $show = function ($value, string $field) use ($positionNames) {
        if ($field === 'positions') {
            return collect($value ?: [])->map(fn ($p) => ($p['kind'] === 'managers' ? 'Quản lý' : ($positionNames[$p['position_id']] ?? '#' . $p['position_id'])) . ' ' . ($p['min_staff'] ?? '?') . '/' . ($p['target_staff'] ?? '?'))->implode(', ') ?: '—';
        }

        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Có' : 'Không',
            default => (string) $value,
        };
    };
@endphp

@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h3 class="card-title">Định biên #{{ $model->id }} · {{ $model->outlet?->code }} · {{ $model->template?->code }} · {{ $model->scheduleLabel() }}</h3>
            <div class="d-flex gap-2">
                @if (Auth::user()->hasPermission('roster.settings'))
                    <a href="{{ route('roster.requirements.edit', $model->id) }}" class="btn btn-sm">Sửa</a>
                @endif
                <a href="{{ route('roster.requirements.index') }}" class="btn btn-sm">‹ Danh sách</a>
            </div>
        </div>
        <div class="list-group list-group-flush">
            @forelse ($model->revisions as $revision)
                @php
                    $before = $revision->before ?? [];
                    $after = $revision->after ?? [];
                    $changed = array_filter(array_keys($labels), fn ($field) => ($before[$field] ?? null) != ($after[$field] ?? null));
                @endphp
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <div><span class="badge bg-secondary-lt">{{ $actions[$revision->action] ?? $revision->action }}</span>
                            <strong>{{ $revision->user?->name ?? 'Hệ thống' }}</strong> · {{ $revision->created_at->format('d/m/Y H:i') }}</div>
                        @if ($revision->reason)<div class="text-muted">Lý do: {{ $revision->reason }}</div>@endif
                    </div>
                    @if ($changed)
                        <table class="table table-sm mt-2 mb-0">
                            <thead><tr><th>Trường</th><th>Trước</th><th>Sau</th></tr></thead>
                            <tbody>
                                @foreach ($changed as $field)
                                    <tr><td>{{ $labels[$field] }}</td><td class="text-muted">{{ $show($before[$field] ?? null, $field) }}</td><td>{{ $show($after[$field] ?? null, $field) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @empty
                <div class="list-group-item text-muted">Chưa có lịch sử (dòng tạo trước khi có chức năng ghi lịch sử).</div>
            @endforelse
        </div>
    </div>
@endsection
