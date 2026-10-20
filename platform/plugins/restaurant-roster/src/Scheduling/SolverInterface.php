<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Bộ giải có thể thay thế (ví dụ dịch vụ OR-Tools CP-SAT sau này) miễn trả về SolveResult.
 */
interface SolverInterface
{
    public function name(): string;

    public function solve(Problem $problem): SolveResult;
}
