@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Tạo kỳ lịch</h3></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('roster.periods.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label required" for="period-name">Tên kỳ</label>
                            <input type="text" class="form-control" id="period-name" name="name" value="{{ $suggested['name'] }}" maxlength="120" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label required" for="period-start">Từ ngày</label>
                                <input type="date" class="form-control" id="period-start" name="start_date" value="{{ $suggested['start'] }}" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label required" for="period-end">Đến ngày</label>
                                <input type="date" class="form-control" id="period-end" name="end_date" value="{{ $suggested['end'] }}" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><x-core::icon name="ti ti-plus" /> Tạo kỳ lịch</button>
                    </form>
                    <ol class="small text-muted mt-3 mb-0 ps-3">
                        <li>Tạo kỳ lịch (thường theo tháng).</li>
                        <li>Nhập OFF nhân viên nhắn qua Zalo – OFF được ghi vào lịch và khóa ngay.</li>
                        <li>Xem tổng hợp OFF, ngày có nguy cơ thiếu người; gỡ OFF nếu cần.</li>
                        <li>Bấm <strong>Xác nhận đã nhập xong OFF</strong>, rồi chạy Tạo lịch tự động.</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Các kỳ lịch</h3></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Kỳ</th><th>Phạm vi</th><th>Nhập OFF</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($periods as $period)
                                <tr>
                                    <td class="fw-semibold">{{ $period->name }}</td>
                                    <td>{{ $period->start_date->format('d/m') }} – {{ $period->end_date->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($period->off_confirmed_at)
                                            <span class="badge bg-success-lt">Đã xong ({{ $period->off_confirmed_at->format('d/m H:i') }})</span>
                                        @else
                                            <span class="badge bg-warning-lt">Đang nhập OFF</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('roster.periods.show', $period->id) }}" class="btn btn-sm">Xem tổng hợp</a>
                                        @if (Auth::user()->hasPermission('roster.approve'))
                                            <form method="POST" action="{{ route('roster.periods.destroy', $period->id) }}" class="d-inline"
                                                onsubmit="return confirm('Xóa kỳ lịch {{ $period->name }}? Lịch làm việc và OFF đã ghi không bị xóa.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa kỳ lịch (không xóa lịch làm việc)"><x-core::icon name="ti ti-trash" /></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Chưa có kỳ lịch nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
