<?php

namespace Botble\RestaurantRoster\Scheduling;

use InvalidArgumentException;

/**
 * Dữ liệu đầu vào đầy đủ của một lần xếp lịch. Độc lập với database và giao diện.
 */
final class Problem
{
    /** @var array<int, EmployeeSpec> */
    public readonly array $employees;

    /** @var array<int, ShiftSpec> */
    public readonly array $shifts;

    /** @var array<string, Requirement> */
    public readonly array $requirements;

    /** @var array<int, array<string, string>> [employeeId][date] => "outletId|shiftId|cờ (L)" (đã chuẩn hóa) */
    public readonly array $baseline;

    /**
     * @param string[] $days Các ngày được xếp (Y-m-d, liên tục, tăng dần)
     * @param EmployeeSpec[] $employees
     * @param ShiftSpec[] $shifts
     * @param Requirement[] $requirements
     * @param array<int, array<string, string>> $hardUnavailable [employeeId][date] => lý do (OFF đã duyệt, nghỉ phép, sự kiện tập thể)
     * @param array<int, array<string, string>> $softUnavailable [employeeId][date] => lý do (đơn chưa duyệt, ngày bận)
     * @param FixedAssignment[] $fixed Ô khóa trong phạm vi + ô ngữ cảnh ngoài phạm vi
     * @param array<int, array<string, string>> $baseline [employeeId][date] => "outletId|shiftId" lịch hiện tại (để giữ ổn định khi tối ưu lại)
     * @param array<int, string> $outletNames
     * @param array<int, array<string, bool>> $regularOff Ngày nghỉ cứng nhưng là ngày OFF THƯỜNG LỆ của tuần (vd sinh nhật):
     *                                                  không làm giảm định mức ngày làm khi tính công bằng.
     */
    public function __construct(
        public readonly array $days,
        array $employees,
        array $shifts,
        array $requirements,
        public readonly Rules $rules,
        public readonly array $hardUnavailable = [],
        public readonly array $softUnavailable = [],
        public readonly array $fixed = [],
        array $baseline = [],
        public readonly array $outletNames = [],
        public readonly array $regularOff = [],
        /** @var Coverage[] Định biên phủ trạm hỗ trợ (Lobby) theo khung giờ */
        public readonly array $coverage = [],
        // Outlet đại diện trạm hỗ trợ (LOBBY): ca làm trọn ở trạm (OH, M9) xếp vào outlet này.
        public readonly ?int $stationOutletId = null,
        /** @var array<int, float> [employeeId] => số lượt Lobby trung bình mỗi tuần trong các tuần ngay trước kỳ (để luân phiên giữa các tuần) */
        public readonly array $lobbyHistory = [],
        /** @var array<int, int> [employeeId] => số tuần có làm ca đêm trong 4 tuần ngay trước kỳ (luân phiên ca Night) */
        public readonly array $nightHistory = [],
        /** @var array<int, string> */
        public readonly array $positionNames = [],
        /** @var array<int, int> [outletId khu] => outletId nhà hàng chủ quản (vd Kid-Deline → Gusto) */
        public readonly array $outletParents = [],
        /** @var array<string, array{ids: int[], label: string}> Nhóm vị trí dùng cho định biên tối thiểu theo vị trí (vd "Quản lý": Junior Captain trở lên) */
        public readonly array $positionGroups = [],
        /** @var array<int, array<string, int>> [employeeId][loại ca hỗ trợ] => số lượt trong historyWeeks tuần trước kỳ, vd "OH(L)", "M9(L)", "M6(L)", "A2(L)", "M6(K)", "A2(K)" */
        public readonly array $supportHistory = [],
        /** @var array<int, array{morning: int, afternoon: int}> [employeeId] => số ca sáng / chiều trong historyWeeks tuần trước kỳ */
        public readonly array $shiftHistory = [],
        // Khoảng đánh giá công bằng (số tuần lịch sử, vd 4 hoặc 8 tuần).
        public readonly int $historyWeeks = 4,
        /**
         * @var array<string, int[]> Kế hoạch luân phiên Night: [ngày] => nhân viên luân phiên được làm Night ngày đó (theo tuần đã chọn).
         *      Ngày có trong mảng: nhân viên luân phiên KHÔNG có tên không được tự xếp Night; ngày không có: không giới hạn.
         */
        public readonly array $nightPlan = [],
    ) {
        if (! $days) {
            throw new InvalidArgumentException('Phạm vi xếp lịch không có ngày nào.');
        }

        $normalized = [];

        foreach ($baseline as $employeeId => $cells) {
            foreach ($cells as $date => $slot) {
                [$o, $s, $f] = ScheduleState::parseSlot((string) $slot);
                $normalized[$employeeId][$date] = ScheduleState::slotKey($o, $s, $f);
            }
        }

        $this->baseline = $normalized;
        $this->employees = array_column(array_map(fn (EmployeeSpec $e) => [$e->id, $e], $employees), 1, 0);
        $this->shifts = array_column(array_map(fn (ShiftSpec $s) => [$s->id, $s], $shifts), 1, 0);

        $indexed = [];

        foreach ($requirements as $requirement) {
            if (! isset($this->shifts[$requirement->shiftId])) {
                throw new InvalidArgumentException("Định biên tham chiếu ca #{$requirement->shiftId} không tồn tại.");
            }

            $indexed[$requirement->key()] = $requirement;
        }

        $this->requirements = $indexed;
    }

    /**
     * Nhân viên luân phiên Night (không phải Night cố định / đa năng, outlet chính không phải outlet Night):
     * ngày này có kế hoạch luân phiên và người này có tên trong đó? null = ngày không có kế hoạch.
     */
    public function nightPlanned(int $employeeId, string $date): ?bool
    {
        return isset($this->nightPlan[$date]) ? in_array($employeeId, $this->nightPlan[$date], true) : null;
    }

    /**
     * Tên loại ca hỗ trợ dùng để theo dõi lượt và luân phiên: mã ca + (L) khi có mặt ở Lobby, + (K) khi đứng khu trực thuộc
     * (Kid-Deline). OH và OH(L) là cùng nhóm mã OH; M9 làm trọn ở Lobby luôn là M9(L).
     */
    public static function supportTypeKey(string $shiftCode, string $kind): string
    {
        return strtoupper($shiftCode) . ($kind === 'kid' ? '(K)' : '(L)');
    }

    /**
     * Outlet thuộc "nhà" của nhân viên: chính outlet chính, hoặc khu trực thuộc outlet chính (Kid-Deline của Gusto).
     */
    public function isHomeOutlet(EmployeeSpec $employee, ?int $outletId): bool
    {
        return $outletId !== null && ($outletId === $employee->homeOutletId || ($this->outletParents[$outletId] ?? null) === $employee->homeOutletId);
    }

    /**
     * Các nhóm vị trí chứa vị trí này (khóa số = chính positionId, dùng khi định biên ghi thẳng một vị trí).
     *
     * @return string[]
     */
    public function groupsOfPosition(?int $positionId): array
    {
        if ($positionId === null) {
            return [];
        }

        $groups = [(string) $positionId];

        foreach ($this->positionGroups as $key => $group) {
            if (in_array($positionId, $group['ids'], true)) {
                $groups[] = (string) $key;
            }
        }

        return $groups;
    }

    public function positionGroupLabel(int|string $group): string
    {
        return $this->positionGroups[$group]['label'] ?? $this->positionNames[(int) $group] ?? "Vị trí {$group}";
    }

    public function outletName(?int $outletId): string
    {
        return $outletId === null ? '—' : ($this->outletNames[$outletId] ?? "Outlet #{$outletId}");
    }

    /**
     * Mã băm đầu vào để nhận biết hai lần chạy trên cùng dữ liệu.
     */
    public function hash(): string
    {
        return hash('sha256', serialize([
            $this->days, $this->employees, $this->shifts, $this->requirements, $this->rules,
            $this->hardUnavailable, $this->softUnavailable, $this->fixed, $this->baseline, $this->regularOff, $this->coverage, $this->lobbyHistory, $this->nightHistory,
            $this->supportHistory, $this->shiftHistory, $this->historyWeeks, $this->nightPlan,
        ]));
    }
}
