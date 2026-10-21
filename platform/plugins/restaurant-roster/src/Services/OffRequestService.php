<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Quy trình OFF của quản lý (quyết định 21/10/2026):
 * nhân viên nhắn OFF qua nhóm Zalo → quản lý nhập vào phần mềm = đã cho OFF → OFF được ghi vào bảng lịch và KHÓA ngay
 * (thuật toán không bao giờ đổi). Thiếu người thì quản lý "gỡ OFF" ô đó rồi xếp ca như bình thường.
 *
 * Mọi thay đổi ô OFF đi qua service này để: không ghi đè ca đã xác nhận khi chưa được quản lý quyết định,
 * phát hiện đơn trùng ngày, và lưu nhật ký từng ô.
 */
class OffRequestService
{
    /** Loại đơn ghi thành ô nghỉ phép (AL/SL) thay vì OFF. */
    public const LEAVE_TYPES = ['annual_leave' => 'AL', 'sick' => 'SL'];

    public function __construct(protected RosterAssigner $assigner)
    {
    }

    /**
     * Kiểm tra trước khi ghi OFF cho các nhân viên trong khoảng ngày.
     *
     * @param int[] $employeeIds
     * @return array{duplicates: array<int, array{employee: string, request_id: int, range: string}>, locked: array<int, array{employee: string, date: string, notation: string}>}
     */
    public function conflicts(array $employeeIds, string $from, string $to, ?int $ignoreRequestId = null): array
    {
        $names = Employee::query()->whereIn('id', $employeeIds)->pluck('name', 'id');

        $duplicates = AvailabilityRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('date_from', '<=', $to)
            ->whereDate('date_to', '>=', $from)
            ->when($ignoreRequestId, fn ($query) => $query->whereKeyNot($ignoreRequestId))
            ->get()
            ->map(fn (AvailabilityRequest $r) => [
                'employee' => (string) ($names[$r->employee_id] ?? "#{$r->employee_id}"),
                'request_id' => (int) $r->getKey(),
                'range' => $r->date_from->format('d/m') . ($r->date_from->equalTo($r->date_to) ? '' : ' → ' . $r->date_to->format('d/m')),
            ])->values()->all();

        // Ca làm đã khóa (đã xác nhận, tiệc BQ, khóa tay) trong khoảng ngày: không tự đè, quản lý phải quyết định.
        $locked = Shift::query()
            ->with('template:id,code')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from, $to])
            ->where('kind', Shift::KIND_SHIFT)
            ->where('is_locked', true)
            ->when($ignoreRequestId, fn ($query) => $query->where(fn ($q) => $q->whereNull('availability_request_id')->orWhere('availability_request_id', '!=', $ignoreRequestId)))
            ->get()
            ->map(fn (Shift $s) => [
                'employee' => (string) ($names[$s->employee_id] ?? "#{$s->employee_id}"),
                'date' => $s->date->format('d/m/Y'),
                'notation' => $s->notation,
            ])->values()->all();

        return ['duplicates' => $duplicates, 'locked' => $locked];
    }

    /**
     * Ghi OFF cho từng nhân viên: tạo đơn (đã cho OFF) và ghi + khóa các ô lịch.
     *
     * @param int[] $employeeIds
     * @param bool $overrideLocked true = quản lý đã chọn cho OFF đè lên ca làm đã khóa
     * @return array{requests: AvailabilityRequest[], written: int, kept: array<int, string>}
     */
    public function record(array $employeeIds, array $data, ?int $userId, bool $overrideLocked = false): array
    {
        $result = ['requests' => [], 'written' => 0, 'kept' => []];

        DB::transaction(function () use ($employeeIds, $data, $userId, $overrideLocked, &$result): void {
            foreach (array_unique($employeeIds) as $employeeId) {
                $request = AvailabilityRequest::query()->create([
                    ...$data,
                    'employee_id' => $employeeId,
                    'status' => 'approved',
                    'reviewed_by' => $userId,
                    'reviewed_at' => now(),
                    'created_by' => $userId,
                ]);

                $applied = $this->apply($request, $userId, $overrideLocked);
                $result['requests'][] = $request;
                $result['written'] += $applied['written'];
                $result['kept'] = [...$result['kept'], ...$applied['kept']];
            }
        });

        return $result;
    }

    /**
     * Ghi các ngày của đơn vào bảng lịch và khóa. Ca làm đã khóa không bị đè (trừ khi quản lý chọn đè):
     * ô đó được đánh dấu "cần xem xét" để quản lý quyết định.
     *
     * @return array{written: int, kept: array<int, string>}
     */
    public function apply(AvailabilityRequest $request, ?int $userId, bool $overrideLocked = false): array
    {
        $employee = Employee::query()->findOrFail($request->employee_id);
        $labels = config('plugins.restaurant-roster.general.availability_types', []);
        $label = trim(($labels[$request->type] ?? 'OFF') . ($request->group_label ? " – {$request->group_label}" : '') . ($request->reason ? ": {$request->reason}" : ''));
        $written = 0;
        $kept = [];

        foreach (CarbonPeriod::create($request->date_from, $request->date_to) as $day) {
            $date = CarbonImmutable::parse($day)->startOfDay();
            $existing = Shift::query()->with('template')->where('employee_id', $employee->getKey())->whereDate('date', $date)->first();

            if ($existing && $existing->kind === Shift::KIND_SHIFT && $existing->is_locked && ! $overrideLocked) {
                $existing->update([
                    'needs_review' => true,
                    'review_note' => "Có OFF mới (đơn #{$request->getKey()}) trùng ca đã khóa {$existing->notation} – giữ ca hay cho OFF?",
                ]);
                $kept[] = "{$employee->name} {$date->format('d/m')} ({$existing->notation})";

                continue;
            }

            $before = $existing ? $this->snapshot($existing) : null;
            $leave = self::LEAVE_TYPES[$request->type] ?? null;
            $cell = $this->assigner->assign($employee, $date, [
                'kind' => $leave ? Shift::KIND_LEAVE : Shift::KIND_OFF,
                'leave_type' => $leave,
                'note' => mb_substr($label, 0, 255),
                'source' => 'manual',
            ], $userId);

            $cell->update([
                'is_locked' => true,
                'lock_reason' => Shift::LOCK_OFF_REQUEST,
                'locked_by' => $userId,
                'locked_at' => now(),
                'availability_request_id' => $request->getKey(),
                'needs_review' => false,
                'review_note' => null,
            ]);

            $this->log($employee->getKey(), $date, $before, $cell->refresh(), 'off_record', "Ghi OFF từ đơn #{$request->getKey()}: {$label}", $userId);
            $written++;
        }

        return ['written' => $written, 'kept' => $kept];
    }

    /**
     * Gỡ OFF một ô (thiếu người – quản lý cho nhân viên đi làm): xóa ô OFF để xếp ca được.
     * Đơn còn ngày OFF khác thì giữ; hết ngày thì đơn chuyển "Đã hủy".
     */
    public function removeCell(Shift $cell, ?int $userId, ?string $reason = null): void
    {
        if (! $cell->is_locked || $cell->kind === Shift::KIND_SHIFT) {
            throw new RuntimeException('Ô này không phải OFF đã ghi.');
        }

        DB::transaction(function () use ($cell, $userId, $reason): void {
            $requestId = $cell->availability_request_id;
            $before = $this->snapshot($cell);
            $cell->delete();
            $this->log($cell->employee_id, $cell->date, $before, null, 'off_remove', $reason ?: 'Gỡ OFF để xếp ca', $userId);

            if ($requestId && ! Shift::query()->where('availability_request_id', $requestId)->exists()) {
                AvailabilityRequest::query()->whereKey($requestId)->update(['status' => 'cancelled']);
            }
        });
    }

    /**
     * Gỡ toàn bộ OFF của một đơn (hủy đơn, xóa đơn, hoặc trước khi ghi lại theo ngày mới).
     */
    public function withdraw(AvailabilityRequest $request, ?int $userId, string $reason): int
    {
        $cells = Shift::query()->where('availability_request_id', $request->getKey())->where('kind', '!=', Shift::KIND_SHIFT)->get();

        foreach ($cells as $cell) {
            $before = $this->snapshot($cell);
            $cell->delete();
            $this->log($cell->employee_id, $cell->date, $before, null, 'off_remove', $reason, $userId);
        }

        // Ô ca làm từng bị đánh dấu xem xét vì đơn này: bỏ đánh dấu.
        Shift::query()->where('needs_review', true)->where('review_note', 'like', "%(đơn #{$request->getKey()})%")
            ->update(['needs_review' => false, 'review_note' => null]);

        return $cells->count();
    }

    /**
     * Quản lý quyết định ô "cần xem xét" do OFF trùng ca đã khóa: giữ ca (bỏ đánh dấu) hoặc cho OFF (ghi đè ca).
     */
    public function resolveReview(Shift $cell, bool $giveOff, ?int $userId): void
    {
        if (! $cell->needs_review) {
            throw new RuntimeException('Ô này không cần xem xét.');
        }

        if (! $giveOff) {
            $cell->update(['needs_review' => false, 'review_note' => null]);
            $this->log($cell->employee_id, $cell->date, null, $this->snapshot($cell), 'review_keep', 'Giữ ca đã khóa, không cho OFF', $userId);

            return;
        }

        preg_match('/đơn #(\d+)/u', (string) $cell->review_note, $match);
        $request = isset($match[1]) ? AvailabilityRequest::query()->find((int) $match[1]) : null;

        if (! $request) {
            throw new RuntimeException('Không tìm thấy đơn OFF liên quan.');
        }

        DB::transaction(function () use ($cell, $request, $userId): void {
            $date = $cell->date->toDateString();
            $single = clone $request;
            $single->date_from = CarbonImmutable::parse($date);
            $single->date_to = CarbonImmutable::parse($date);
            $this->apply($single, $userId, overrideLocked: true);
        });
    }

    public function snapshot(Shift $shift): array
    {
        return [
            'kind' => $shift->kind,
            'outlet_id' => $shift->outlet_id,
            'shift_template_id' => $shift->shift_template_id,
            'notation' => $shift->notation,
            'status' => $shift->status,
            'is_locked' => (bool) $shift->is_locked,
            'lock_reason' => $shift->lock_reason,
            'availability_request_id' => $shift->availability_request_id,
        ];
    }

    protected function log(int $employeeId, $date, ?array $before, array|Shift|null $after, string $action, string $reason, ?int $userId): void
    {
        ChangeLog::query()->create([
            'employee_id' => $employeeId,
            'date' => CarbonImmutable::parse($date)->toDateString(),
            'before' => $before,
            'after' => is_array($after) ? $after : ($after ? $this->snapshot($after) : null),
            'action' => $action,
            'reason' => mb_substr($reason, 0, 500),
            'user_id' => $userId,
        ]);
    }
}
