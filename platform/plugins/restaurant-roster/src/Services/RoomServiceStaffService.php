<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\RoomServiceStaff;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Nhân sự Room Service: chuyên trách từng ca (M6, A2…), dự phòng, người đã xác nhận đủ kỹ năng thay thế.
 * Đổi người chuyên trách: dòng cũ kết thúc hiệu lực, dòng mới bắt đầu – không xóa lịch sử, không tự sửa lịch đã xếp
 * (chỉ liệt kê các ngày cần xem lại để quản lý chạy "Tối ưu lại" hoặc giữ nguyên).
 */
class RoomServiceStaffService
{
    public function outletId(): ?int
    {
        $codes = (array) config('plugins.restaurant-roster.general.scheduling.room_service_codes', ['ROOM', 'RS', 'ROOMSERVICE']);
        $id = Outlet::query()->whereIn('code', $codes)->value('id') ?? Outlet::query()->where('name', 'like', '%Room%')->value('id');

        return $id ? (int) $id : null;
    }

    /** @return Collection<int, RoomServiceStaff> dòng đang hoạt động có hiệu lực giao với [from, to] */
    public function effectiveRows(CarbonInterface $from, ?CarbonInterface $to = null): Collection
    {
        return RoomServiceStaff::query()->effective($from, $to)->with(['employee', 'template'])->get();
    }

    /** @return int[] nhân viên chuyên trách Room Service (không vào danh sách luân phiên Night) */
    public function leadIds(CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        return $this->effectiveRows($from, $to)->where('role', RoomServiceStaff::ROLE_LEAD)->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Thêm một dòng cấu hình. Chuyên trách một ca: dòng chuyên trách đang hiệu lực của ca đó được kết thúc vào hôm trước ngày bắt đầu.
     *
     * @return array{row: RoomServiceStaff, closed: ?RoomServiceStaff, affected: array}
     */
    public function save(array $data, ?int $userId): array
    {
        $role = (string) ($data['role'] ?? '');

        if (! isset(RoomServiceStaff::ROLES[$role])) {
            throw new InvalidArgumentException('Vai trò không hợp lệ.');
        }

        if ($role === RoomServiceStaff::ROLE_LEAD && empty($data['shift_template_id'])) {
            throw new InvalidArgumentException('Chuyên trách cần chọn ca (vd M6, A2).');
        }

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $from = ! empty($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::today();
        $to = ! empty($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null;

        if ($to && $to->lt($from)) {
            throw new InvalidArgumentException('Ngày kết thúc phải sau ngày bắt đầu.');
        }

        return DB::transaction(function () use ($data, $role, $employee, $from, $to, $userId) {
            $closed = null;

            if ($role === RoomServiceStaff::ROLE_LEAD) {
                $closed = RoomServiceStaff::query()->effective($from, $to ?? $from->addYears(50))->where('role', RoomServiceStaff::ROLE_LEAD)
                    ->where('shift_template_id', (int) $data['shift_template_id'])->where('employee_id', '!=', $employee->getKey())->first();

                if ($closed) {
                    $before = $closed->snapshot();
                    $closed->update(['effective_to' => $from->subDay()->toDateString(), 'updated_by' => $userId,
                        'is_active' => ! $closed->effective_from || $closed->effective_from->lte($from->subDay())]);
                    $this->log($closed->employee_id, $from, $before, $closed->snapshot(), 'Kết thúc chuyên trách Room Service (đổi người)', $userId);
                }
            }

            $row = RoomServiceStaff::query()->create([
                'employee_id' => $employee->getKey(), 'role' => $role,
                'shift_template_id' => $role === RoomServiceStaff::ROLE_LEAD ? (int) $data['shift_template_id'] : null,
                'effective_from' => $from->toDateString(), 'effective_to' => $to?->toDateString(), 'is_active' => true,
                'note' => $data['note'] ?? null, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $this->log($employee->getKey(), $from, null, $row->snapshot(), 'Thêm ' . mb_strtolower($row->roleLabel()) . ' Room Service', $userId);

            return ['row' => $row, 'closed' => $closed, 'affected' => $closed ? $this->affected($closed->employee_id, $employee->getKey(), $from, (int) $data['shift_template_id']) : []];
        });
    }

    /** Kết thúc hiệu lực một dòng (giữ lịch sử). */
    public function end(RoomServiceStaff $row, ?int $userId, ?string $reason = null): void
    {
        $before = $row->snapshot();
        $today = CarbonImmutable::today();
        $row->update(['effective_to' => $today->subDay()->toDateString(), 'is_active' => ! $row->effective_from || $row->effective_from->lt($today), 'updated_by' => $userId]);
        $this->log($row->employee_id, $today, $before, $row->snapshot(), 'Kết thúc ' . mb_strtolower($row->roleLabel()) . ' Room Service' . ($reason ? ': ' . $reason : ''), $userId);
    }

    /**
     * Lịch đã xếp từ ngày đổi người cần xem lại: ca Room Service của người cũ, ca khác của người mới (sẽ phải chuyển sang Room Service).
     *
     * @return array<int, array{date: string, text: string, published: bool, locked: bool}>
     */
    public function affected(int $oldId, int $newId, CarbonInterface $from, int $templateId): array
    {
        $room = $this->outletId();

        return Shift::query()->whereIn('employee_id', [$oldId, $newId])->whereDate('date', '>=', $from->toDateString())
            ->where('kind', Shift::KIND_SHIFT)->with(['employee:id,name', 'template:id,code'])->orderBy('date')->get()
            ->filter(fn (Shift $s) => (int) $s->employee_id === $oldId ? (int) $s->outlet_id === $room : ! ((int) $s->outlet_id === $room && (int) $s->shift_template_id === $templateId))
            ->map(fn (Shift $s) => ['date' => $s->date->toDateString(), 'published' => $s->status === Shift::STATUS_PUBLISHED, 'locked' => (bool) $s->is_locked,
                'text' => sprintf('%s: %s đang có ca %s%s', $s->date->format('d/m'), $s->employee?->name, $s->template?->code,
                    (int) $s->employee_id === $oldId ? ' ở Room Service (người chuyên trách cũ)' : ' – người chuyên trách mới chưa ở Room Service')])
            ->values()->all();
    }

    /**
     * Từng ngày × ca Room Service: người chuyên trách dự kiến, người thực tế, người thay và lý do, outlet nguồn, cảnh báo.
     */
    public function daily(CarbonInterface $from, int $days = 14): array
    {
        $room = $this->outletId();
        $start = CarbonImmutable::parse($from);
        $end = $start->addDays($days - 1);

        if (! $room) {
            return [];
        }

        $rows = $this->effectiveRows($start, $end);
        $leads = $rows->where('role', RoomServiceStaff::ROLE_LEAD);
        $configured = $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->merge(EmployeeSkill::query()->where('outlet_id', $room)->pluck('employee_id'))->unique()->all();
        $shifts = Shift::query()->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
            ->with(['employee:id,name,outlet_id', 'employee.outlet:id,code', 'template:id,code'])->get();
        $byEmployeeDay = $shifts->keyBy(fn (Shift $s) => $s->employee_id . '|' . $s->date->toDateString());
        $atRoom = $shifts->filter(fn (Shift $s) => (int) $s->outlet_id === $room && $s->kind === Shift::KIND_SHIFT)->groupBy(fn (Shift $s) => $s->date->toDateString() . '|' . $s->shift_template_id);
        $result = [];

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $date = $day->toDateString();

            foreach ($leads->groupBy('shift_template_id') as $templateId => $candidates) {
                $lead = $candidates->first(fn (RoomServiceStaff $r) => $r->coversDate($date));
                $actual = $atRoom[$date . '|' . $templateId] ?? collect();
                $leadCell = $lead ? $byEmployeeDay[$lead->employee_id . '|' . $date] ?? null : null;
                $leadWorks = $actual->contains(fn (Shift $s) => $lead && (int) $s->employee_id === (int) $lead->employee_id);
                $warnings = [];
                $reason = null;

                if (! $lead) {
                    $warnings[] = 'Chưa có người chuyên trách hiệu lực cho ca này.';
                } elseif (! $leadWorks) {
                    $reason = match (true) {
                        ! $leadCell => 'người chuyên trách chưa có ca',
                        $leadCell->kind !== Shift::KIND_SHIFT => 'người chuyên trách ' . ($leadCell->kind === Shift::KIND_LEAVE ? 'nghỉ phép' : 'OFF'),
                        default => 'người chuyên trách làm ' . ($leadCell->template?->code ?? 'ca khác') . ' ở ' . ($leadCell->outlet_id === $room ? 'Room Service' : 'outlet khác'),
                    };
                }

                if ($actual->isEmpty()) {
                    $warnings[] = 'Không có ai được phân công – cần người dự phòng hoặc người đã xác nhận kỹ năng.';
                }

                foreach ($actual as $s) {
                    if (! in_array((int) $s->employee_id, $configured, true)) {
                        $warnings[] = "Không thể dùng {$s->employee?->name} thay Room Service vì chưa xác nhận kỹ năng.";
                    }
                }

                $result[] = [
                    'date' => $day, 'template' => $candidates->first()->template, 'lead' => $lead?->employee, 'reason' => $reason,
                    'actual' => $actual->map(fn (Shift $s) => ['name' => $s->employee?->name, 'source' => $s->employee?->outlet?->code,
                        'substitute' => ! $lead || (int) $s->employee_id !== (int) $lead->employee_id])->values()->all(),
                    'warnings' => $warnings,
                ];
            }
        }

        return $result;
    }

    private function log(int $employeeId, CarbonInterface $date, ?array $before, ?array $after, string $reason, ?int $userId): void
    {
        ChangeLog::query()->create(['employee_id' => $employeeId, 'date' => $date->toDateString(), 'action' => 'room_service',
            'before' => $before, 'after' => $after, 'reason' => mb_substr($reason, 0, 500), 'user_id' => $userId]);
    }
}
