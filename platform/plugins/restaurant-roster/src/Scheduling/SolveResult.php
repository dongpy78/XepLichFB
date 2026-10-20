<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Kết quả có cấu trúc của một lần giải.
 *
 * Trạng thái:
 *  - feasible:   mọi ràng buộc cứng và định biên tối thiểu đều đạt. Thuật toán heuristic
 *                không chứng minh được tối ưu nên KHÔNG bao giờ báo "optimal".
 *  - partial:    có lịch hợp lệ nhưng còn ô thiếu so với tối thiểu, và chưa chứng minh được là không thể đủ.
 *  - infeasible: chứng minh được không thể đạt (ví dụ số người đủ điều kiện còn rảnh < tối thiểu),
 *                hoặc ô đã khóa tự mâu thuẫn với ràng buộc cứng. Vẫn trả về phương án tốt nhất tìm được.
 *  - timeout:    hết thời gian trước khi dựng xong phương án ban đầu.
 *  - error:      lỗi dữ liệu đầu vào hoặc lỗi hệ thống.
 */
final class SolveResult
{
    public const FEASIBLE = 'feasible';
    public const PARTIAL = 'partial';
    public const INFEASIBLE = 'infeasible';
    public const TIMEOUT = 'timeout';
    public const ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly array $assignments = [],
        public readonly array $unfilledRequirements = [],
        public readonly array $hardConstraintViolations = [],
        public readonly array $softConstraintScores = [],
        public readonly array $employeeWorkload = [],
        public readonly array $explanation = [],
        public readonly array $solverMetadata = [],
    ) {
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::FEASIBLE, self::PARTIAL, self::INFEASIBLE], true) && $this->assignments;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'assignments' => $this->assignments,
            'unfilled_requirements' => $this->unfilledRequirements,
            'hard_constraint_violations' => $this->hardConstraintViolations,
            'soft_constraint_scores' => $this->softConstraintScores,
            'employee_workload' => $this->employeeWorkload,
            'proposed_reassignments' => array_merge(...array_map(fn ($u) => $u['proposals'] ?? [], $this->unfilledRequirements ?: [[]])),
            'explanation' => $this->explanation,
            'solver_metadata' => $this->solverMetadata,
        ];
    }
}
