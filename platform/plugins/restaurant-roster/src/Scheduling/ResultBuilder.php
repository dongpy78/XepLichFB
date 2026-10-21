<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Chuyển trạng thái lịch sau khi giải thành SolveResult: phân công, ô thiếu người kèm lý do
 * và phương án xử lý, khối lượng công việc từng người, điểm các mục tiêu mềm.
 */
final class ResultBuilder
{
    public function build(ScheduleState $state, bool $constructed, array $metadata): SolveResult
    {
        $problem = $state->problem;
        $hardViolations = $state->validate();

        foreach ($state->sourceShortages() as $row) {
            $hardViolations[] = [
                'employee_id' => $row['employee_id'],
                'date' => $row['date'],
                'outlet_id' => $row['outlet_id'],
                'shift_id' => $row['home_shift_id'],
                'code' => 'source_short',
                'message' => sprintf('Điều sang %s khiến ca %s tại %s thiếu tối thiểu',
                    $problem->outletName($row['outlet_id']), $problem->shifts[$row['home_shift_id']]->code, $problem->outletName($row['home_outlet_id'])),
                'fixed' => false,
            ];
        }

        $unfilled = [...$this->unfilled($state), ...$this->uncoveredWindows($state)];
        usort($unfilled, fn ($a, $b) => [$a['level'] === 'min' ? 0 : 1, $a['date'], $a['outlet_name'], $a['shift_code']] <=> [$b['level'] === 'min' ? 0 : 1, $b['date'], $b['outlet_name'], $b['shift_code']]);
        $objective = $state->objective();

        $provenImpossible = array_filter($unfilled, fn ($u) => $u['level'] === 'min' && $u['provably_impossible']);
        $minShortages = array_filter($unfilled, fn ($u) => $u['level'] === 'min');

        $status = match (true) {
            ! $constructed => SolveResult::TIMEOUT,
            $hardViolations || $provenImpossible => SolveResult::INFEASIBLE,
            (bool) $minShortages => SolveResult::PARTIAL,
            default => SolveResult::FEASIBLE,
        };

        $warnings = [...$this->managerWarnings($state), ...$this->supportWarnings($state)];
        $explanation = $this->explain($state, $status, $hardViolations, $unfilled, $metadata);

        if ($warnings) {
            $explanation[] = sprintf('%d cảnh báo vận hành (quản lý lệch ca sáng / chiều, Lobby vượt định biên, mượn người đứng Kid-Deline) – xem bên dưới.', count($warnings));
        }

        return new SolveResult(
            status: $status,
            assignments: $this->assignments($state),
            unfilledRequirements: array_values($unfilled),
            hardConstraintViolations: array_map(fn ($v) => $v + [
                'employee_name' => $problem->employees[$v['employee_id']]->name ?? '',
                'outlet_name' => $problem->outletName($v['outlet_id']),
                'shift_code' => $problem->shifts[$v['shift_id']]->code ?? '',
            ], $hardViolations),
            softConstraintScores: [
                'objective' => array_combine(ScheduleState::objectiveLabels($state->problem->rules), $objective),
                'fairness' => $this->fairnessSummary($state),
            ],
            employeeWorkload: $this->workload($state),
            explanation: $explanation,
            solverMetadata: $metadata,
            warnings: $warnings,
            supportReport: $this->supportReport($state),
        );
    }

    private function assignments(ScheduleState $state): array
    {
        $rows = [];

        foreach ($state->problem->employees as $employeeId => $employee) {
            foreach ($state->scopeIndexes() as $i) {
                $date = $state->dates[$i];
                $fixed = $state->fixed[$employeeId][$i] ?? null;
                $current = $state->shiftAt($employeeId, $i);
                $baseline = $state->problem->baseline[$employeeId][$date] ?? null;
                $slot = $current ? ScheduleState::slotKey((int) $current[0], $current[1], $current[3]) : null;

                $rows[] = [
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'kind' => $current ? 'shift' : ($fixed->kind ?? 'off'),
                    'outlet_id' => $current[0] ?? null,
                    'support' => (bool) ($current[3] ?? false),
                    'shift_id' => $current[1] ?? null,
                    'locked' => $fixed !== null,
                    'unavailable_reason' => $state->problem->hardUnavailable[$employeeId][$date] ?? null,
                    'changed' => $fixed === null && $baseline !== $slot,
                    'off_home_outlet' => $current && $current[0] !== $employee->homeOutletId,
                ];
            }
        }

        return $rows;
    }

    /**
     * Ô định biên chưa đủ người, kèm phân tích từng nhân viên đủ điều kiện và phương án đề xuất.
     */
    private function unfilled(ScheduleState $state): array
    {
        $problem = $state->problem;
        $result = [];

        foreach ($state->requirements as $i => $requirements) {
            foreach ($requirements as $requirement) {
                $have = $state->count($i, $requirement->outletId, $requirement->shiftId);
                $haveMale = $state->maleCount($i, $requirement->outletId, $requirement->shiftId);
                $positionGaps = [];

                foreach ($requirement->positionMins as $group => $need) {
                    if (($gap = $need - $state->positionCount($i, $requirement->outletId, $requirement->shiftId, $group)) > 0) {
                        $positionGaps[$problem->positionGroupLabel($group)] = $gap;
                    }
                }

                $minGap = max(0, $requirement->min - $have) + max(0, $requirement->minMale - $haveMale) + array_sum($positionGaps);
                $targetGap = max(0, $requirement->target - $have) + max(0, $requirement->targetMale - $haveMale) + array_sum($positionGaps);

                if (! $minGap && ! $targetGap) {
                    continue;
                }

                $level = $minGap ? 'min' : 'target';
                $maleNeeded = $haveMale < ($level === 'min' ? $requirement->minMale : $requirement->targetMale);
                $analysis = $this->analyse($state, $i, $requirement, $maleNeeded);
                $shift = $problem->shifts[$requirement->shiftId];

                $result[] = [
                    'date' => $requirement->date,
                    'outlet_id' => $requirement->outletId,
                    'outlet_name' => $problem->outletName($requirement->outletId),
                    'shift_id' => $requirement->shiftId,
                    'shift_code' => $shift->code,
                    'level' => $level,
                    'min' => $requirement->min,
                    'target' => $requirement->target,
                    'min_male' => $requirement->minMale,
                    'target_male' => $requirement->targetMale,
                    'assigned' => $have,
                    'assigned_male' => $haveMale,
                    'missing_positions' => $positionGaps,
                    'missing' => $level === 'min' ? $minGap : $targetGap,
                    'eligible_total' => $analysis['eligible_total'],
                    'reasons' => $analysis['reasons'],
                    'provably_impossible' => $analysis['possible'] < ($maleNeeded ? $requirement->minMale : $requirement->min) && $level === 'min',
                    'proposals' => $this->proposals($state, $requirement, $level, $have, $analysis),
                ];
            }
        }

        usort($result, fn ($a, $b) => [$a['level'] === 'min' ? 0 : 1, $a['date'], $a['outlet_name'], $a['shift_code']] <=> [$b['level'] === 'min' ? 0 : 1, $b['date'], $b['outlet_name'], $b['shift_code']]);

        return $result;
    }

    /**
     * Khung giờ phủ Lobby chưa đủ người, kèm người có thể giao (L) hoặc xếp thêm ca làm trọn ở Lobby.
     */
    private function uncoveredWindows(ScheduleState $state): array
    {
        $problem = $state->problem;
        $result = [];

        foreach ($state->coverage as $i => $windows) {
            foreach ($windows as $k => [$start, $end, $min, $target, $label]) {
                $have = $state->coverageCount($i, $k);

                if ($have >= $target) {
                    continue;
                }

                $level = $have < $min ? 'min' : 'target';
                $window = sprintf('%s %02d:%02d–%02d:%02d', $label, intdiv($start, 60), $start % 60, intdiv($end, 60), $end % 60);
                $reasons = ['support_candidates' => [], 'station_candidates' => [], 'blocked' => []];
                $proposals = [];
                $eligible = 0;

                foreach ($problem->employees as $employeeId => $employee) {
                    if (! $employee->canSupport($problem->stationOutletId, 0) && ! array_filter(array_keys($employee->skills), fn ($key) => str_starts_with($key, $problem->stationOutletId . ':'))) {
                        continue;
                    }

                    $eligible++;
                    $current = $state->shiftAt($employeeId, $i);

                    if ($current && ! $current[3] && in_array($k, $state->supportWindows($i, $current[0], $current[1], true), true)) {
                        $reasons['support_candidates'][] = sprintf('%s đang làm %s tại %s', $employee->name, $problem->shifts[$current[1]]->code, $problem->outletName($current[0]));
                    } elseif (! $current && ! isset($problem->hardUnavailable[$employeeId][$state->dates[$i]])) {
                        $reasons['station_candidates'][] = $employee->name;
                    }
                }

                foreach (array_slice($reasons['support_candidates'], 0, 3) as $text) {
                    $proposals[] = ['date' => $state->dates[$i], 'type' => 'support', 'label' => 'A', 'text' => "Giao hỗ trợ Lobby (L): {$text}."];
                }

                if ($reasons['station_candidates']) {
                    $proposals[] = ['date' => $state->dates[$i], 'type' => 'station_shift', 'label' => 'B',
                        'text' => 'Xếp thêm ca làm trọn ở Lobby (OH/M9) cho người đang OFF và đồng ý: ' . implode(', ', array_slice($reasons['station_candidates'], 0, 5)) . '.'];
                }

                $proposals[] = ['date' => $state->dates[$i], 'type' => 'keep_warning', 'label' => 'D', 'text' => 'Giữ cảnh báo thiếu người ở Lobby để quản lý chủ động xử lý.'];

                $result[] = [
                    'date' => $state->dates[$i],
                    'outlet_id' => $problem->stationOutletId,
                    'outlet_name' => $problem->outletName($problem->stationOutletId),
                    'shift_id' => null,
                    'shift_code' => $window,
                    'level' => $level,
                    'min' => $min,
                    'target' => $target,
                    'min_male' => 0,
                    'target_male' => 0,
                    'assigned' => $have,
                    'assigned_male' => 0,
                    'missing' => ($level === 'min' ? $min : $target) - $have,
                    'eligible_total' => $eligible,
                    'reasons' => $reasons,
                    'provably_impossible' => false,
                    'proposals' => $proposals,
                ];
            }
        }

        return $result;
    }

    private function analyse(ScheduleState $state, int $i, Requirement $requirement, bool $maleNeeded): array
    {
        $problem = $state->problem;
        $date = $state->dates[$i];
        $reasons = ['approved_off' => [], 'locked' => [], 'busy_elsewhere' => [], 'blocked' => [], 'gender_missing' => [], 'overtime_possible' => [], 'transferable' => []];
        $eligible = 0;
        $possible = 0;

        foreach ($problem->employees as $employeeId => $employee) {
            if ($employee->skillLevel($requirement->outletId, $requirement->shiftId) === null) {
                continue;
            }

            if ($maleNeeded && ! $employee->isMale()) {
                if ($employee->gender === null) {
                    $reasons['gender_missing'][] = $employee->name;
                }

                continue;
            }

            $eligible++;
            $name = $employee->name;

            if ($reason = $problem->hardUnavailable[$employeeId][$date] ?? null) {
                $reasons['approved_off'][] = "{$name} ({$reason})";

                continue;
            }

            $fixed = $state->fixed[$employeeId][$i] ?? null;

            if ($fixed && ! $fixed->isWorking()) {
                $reasons['locked'][] = "{$name} (ô đã khóa: OFF)";

                continue;
            }

            $possible++;
            $current = $state->shiftAt($employeeId, $i);

            if ($current) {
                [$o, $s] = $current;

                if ($o === $requirement->outletId && $s === $requirement->shiftId) {
                    continue;
                }

                $source = $state->requirements[$i]["{$o}|{$s}"] ?? null;
                $label = sprintf('%s đang làm %s tại %s', $name, $problem->shifts[$s]->code, $problem->outletName($o));

                if ($fixed) {
                    $reasons['locked'][] = "{$label} (ô đã khóa)";

                    continue;
                }

                $sourceCount = $state->count($i, (int) $o, $s);
                $sourceMale = $state->maleCount($i, (int) $o, $s);
                $keepsSourceMin = ! $source || ($sourceCount - 1 >= $source->min && $sourceMale - ($employee->isMale() ? 1 : 0) >= $source->minMale);

                if ($keepsSourceMin) {
                    $state->remove($employeeId, $i);
                    $blocked = $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId);
                    $state->place($employeeId, $i, (int) $o, $s, $current[3]);

                    if (! $blocked) {
                        $reasons['transferable'][] = ['employee_id' => $employeeId, 'name' => $name, 'from_outlet_id' => $o, 'from_outlet' => $problem->outletName($o), 'from_shift' => $problem->shifts[$s]->code, 'source_after' => $sourceCount - 1, 'source_min' => $source?->min ?? 0];

                        continue;
                    }
                }

                $reasons['busy_elsewhere'][] = $label . ($keepsSourceMin ? '' : ' (nơi đó sẽ thiếu tối thiểu nếu điều đi)');

                continue;
            }

            $violation = $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId);

            if ($violation && in_array($violation[0], ['days_off_quota', 'weekly_hours'], true)) {
                $metrics = $state->metricsFor($employeeId);
                $reasons['overtime_possible'][] = ['employee_id' => $employeeId, 'name' => $name, 'reason' => $violation[1], 'worked_days' => $metrics['worked'], 'hours' => $metrics['hours']];
            } elseif ($violation) {
                $reasons['blocked'][] = "{$name}: {$violation[1]}";
            }
        }

        usort($reasons['overtime_possible'], fn ($a, $b) => [$a['hours'], $a['worked_days']] <=> [$b['hours'], $b['worked_days']]);

        return ['eligible_total' => $eligible, 'possible' => $possible, 'reasons' => $reasons];
    }

    private function proposals(ScheduleState $state, Requirement $requirement, string $level, int $have, array $analysis): array
    {
        $proposals = [];
        $base = ['date' => $requirement->date, 'outlet_id' => $requirement->outletId, 'shift_id' => $requirement->shiftId];

        foreach (array_slice($analysis['reasons']['transferable'], 0, 3) as $candidate) {
            $proposals[] = $base + [
                'type' => 'transfer',
                'label' => 'A',
                'employee_id' => $candidate['employee_id'],
                'text' => sprintf('Điều %s từ %s %s sang (nơi đó còn %d người, tối thiểu %d).', $candidate['name'], $candidate['from_outlet'], $candidate['from_shift'], $candidate['source_after'], $candidate['source_min']),
            ];
        }

        foreach (array_slice($analysis['reasons']['overtime_possible'], 0, 3) as $candidate) {
            $proposals[] = $base + [
                'type' => 'extra_shift',
                'label' => 'B',
                'employee_id' => $candidate['employee_id'],
                'text' => sprintf('%s nhận thêm ca (tăng ca) nếu đồng ý – hiện %s, đã làm %d ngày / %sh trong kỳ.', $candidate['name'], mb_strtolower($candidate['reason']), $candidate['worked_days'], round($candidate['hours'], 1)),
            ];
        }

        if ($level === 'target' && $requirement->allowReduce) {
            $proposals[] = $base + [
                'type' => 'reduce',
                'label' => 'C',
                'text' => sprintf('Chấp nhận định biên tối thiểu (%d người%s) theo chính sách được phép giảm.', $requirement->min, $requirement->minMale ? ", {$requirement->minMale} nam" : ''),
            ];
        }

        if ($analysis['reasons']['gender_missing']) {
            $proposals[] = $base + [
                'type' => 'data',
                'label' => 'D',
                'text' => 'Khai báo giới tính cho: ' . implode(', ', array_slice($analysis['reasons']['gender_missing'], 0, 6)) . (count($analysis['reasons']['gender_missing']) > 6 ? '…' : '') . '.',
            ];
        }

        $proposals[] = $base + [
            'type' => 'keep_warning',
            'label' => 'D',
            'text' => 'Giữ cảnh báo thiếu người để quản lý chủ động xử lý.',
        ];

        return $proposals;
    }

    private function workload(ScheduleState $state): array
    {
        $rows = [];

        foreach ($state->problem->employees as $employeeId => $employee) {
            $m = $state->metricsFor($employeeId);
            $offDays = count($state->scopeIndexes()) - $m['worked'];

            $rows[] = [
                'employee_id' => $employeeId,
                'name' => $employee->name,
                'worked_days' => $m['worked'],
                'off_days' => $offDays,
                'hours' => round($m['hours'], 2),
                'morning' => $m['morning'],
                'afternoon' => $m['afternoon'],
                'night' => $m['night'],
                'heavy_transitions' => $m['heavy'],
                'lobby' => $m['lobby'],
                'shift_mix' => $m['shift_mix'],
                'over_norm' => $m['over_norm'],
                'off_blocks' => $m['off_blocks'],
                'off_split' => $m['off_split'],
                'child_borrow' => $m['child_borrow'],
                'busy_off' => $m['busy_off'],
                'off_overlong' => $m['off_overlong'],
                'off_home_outlet' => $m['non_home'],
                'unapproved_request_conflicts' => $m['soft_conflicts'],
                'changes' => $m['changes'],
            ];
        }

        return $rows;
    }

    private function fairnessSummary(ScheduleState $state): array
    {
        $hours = array_map(fn ($id) => $state->metricsFor($id)['hours'], array_keys($state->problem->employees));
        $heavy = array_map(fn ($id) => $state->metricsFor($id)['heavy'], array_keys($state->problem->employees));

        if (! $hours) {
            return [];
        }

        $mean = array_sum($hours) / count($hours);

        return [
            'hours_min' => round(min($hours), 1),
            'hours_max' => round(max($hours), 1),
            'hours_mean' => round($mean, 1),
            'hours_stddev' => round(sqrt(array_sum(array_map(fn ($h) => ($h - $mean) ** 2, $hours)) / count($hours)), 2),
            'heavy_total' => array_sum($heavy),
            'heavy_max_per_employee' => max($heavy),
        ];
    }

    /**
     * Báo cáo công bằng ca hỗ trợ (Lobby, Kid-Deline) và ca sáng / chiều – để quản lý thấy vì sao ai được giao, ai chưa có lượt:
     * - lượt từng loại ca của từng người: kỳ này và trong khoảng đánh giá trước kỳ;
     * - người đủ điều kiện một loại ca nhưng chưa có lượt nào trong kỳ;
     * - số nữ ca sáng / chiều theo outlet;
     * - lý do từng phân công hỗ trợ do thuật toán xếp.
     *
     * "Đủ điều kiện" lấy từ cấu hình (năng lực, giới tính hỗ trợ Lobby, khu vực / giới tính / vị trí của mẫu ca), không suy đoán.
     */
    private function supportReport(ScheduleState $state): array
    {
        $problem = $state->problem;
        $weeks = $problem->historyWeeks;
        $types = $this->supportTypeShifts($state);
        $rows = [];
        $idle = [];
        $female = [];

        foreach ($problem->employees as $employeeId => $employee) {
            $m = $state->metricsFor($employeeId);
            $eligible = array_values(array_filter(array_keys($types), fn ($type) => $this->eligibleFor($state, $employee, $types[$type])));
            $history = $problem->supportHistory[$employeeId] ?? [];

            if ($employee->gender === 'female') {
                foreach ($state->scopeIndexes() as $i) {
                    if (($current = $state->shiftAt($employeeId, $i)) && in_array($category = $problem->shifts[$current[1]]->category(), ['morning', 'afternoon'], true)) {
                        $outlet = $problem->outletName($problem->outletParents[$current[0]] ?? $current[0]);
                        $female[$outlet][$category] = ($female[$outlet][$category] ?? 0) + 1;
                    }
                }
            }

            if (! $eligible && ! $m['support_types'] && ! $history) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'name' => $employee->name,
                'outlet' => $problem->outletName($employee->homeOutletId),
                'residence' => $employee->residence,
                'worked' => $m['worked'],
                'morning' => $m['morning'],
                'afternoon' => $m['afternoon'],
                'history_morning' => $problem->shiftHistory[$employeeId]['morning'] ?? 0,
                'history_afternoon' => $problem->shiftHistory[$employeeId]['afternoon'] ?? 0,
                'period' => $m['support_types'],
                'history' => $history,
                'eligible' => $eligible,
            ];

            foreach ($eligible as $type) {
                if (empty($m['support_types'][$type]) && $m['worked'] > 0) {
                    $idle[$type][] = sprintf('%s (%d lượt / %d tuần trước)', $employee->name, $history[$type] ?? 0, $weeks);
                }
            }
        }

        return [
            'history_weeks' => $weeks,
            'types' => array_keys($types),
            'rows' => $rows,
            'idle' => $idle,
            'female_split' => $female,
            'reasons' => $this->supportReasons($state, $types),
        ];
    }

    /**
     * Các loại ca hỗ trợ có nhu cầu trong kỳ: (L) theo khung Lobby, ca làm trọn ở Lobby, ca của khu trực thuộc (Kid-Deline).
     *
     * @return array<string, array{kind: string, shift_id: int, outlet_id: ?int}>
     */
    private function supportTypeShifts(ScheduleState $state): array
    {
        $problem = $state->problem;
        $types = [];

        foreach ($state->requirements as $i => $requirements) {
            foreach ($requirements as $requirement) {
                $shift = $problem->shifts[$requirement->shiftId];

                if (isset($problem->outletParents[$requirement->outletId])) {
                    $types[Problem::supportTypeKey($shift->code, 'kid')] ??= ['kind' => 'kid', 'shift_id' => $shift->id, 'outlet_id' => $requirement->outletId];
                } elseif ($requirement->outletId === $problem->stationOutletId) {
                    $types[Problem::supportTypeKey($shift->code, 'lobby')] ??= ['kind' => 'station', 'shift_id' => $shift->id, 'outlet_id' => $requirement->outletId];
                } elseif ($shift->lobbyWindow && $state->supportWindows($i, $requirement->outletId, $shift->id, true)) {
                    $types[Problem::supportTypeKey($shift->code, 'lobby')] ??= ['kind' => 'lobby', 'shift_id' => $shift->id, 'outlet_id' => null];
                }
            }
        }

        ksort($types);

        return $types;
    }

    private function eligibleFor(ScheduleState $state, EmployeeSpec $employee, array $type): bool
    {
        $problem = $state->problem;
        $shift = $problem->shifts[$type['shift_id']];

        if (($shift->allowedGender !== null && $employee->gender !== $shift->allowedGender)
            || ($shift->allowedResidence !== null && $employee->residence !== $shift->allowedResidence)
            || ($shift->allowedPositionId !== null && $employee->positionId !== $shift->allowedPositionId)) {
            return false;
        }

        return match ($type['kind']) {
            'kid', 'station' => $employee->skillLevel($type['outlet_id'], $shift->id) !== null,
            default => $employee->homeOutletId !== null
                && $employee->skillLevel($employee->homeOutletId, $shift->id) !== null
                && $employee->canSupport($problem->stationOutletId, $shift->id)
                && ($problem->rules->supportGender === null || $employee->gender === $problem->rules->supportGender),
        };
    }

    /**
     * Lý do từng phân công hỗ trợ (ô do thuật toán xếp): lượt tích lũy của người được giao so với người đủ điều kiện khác.
     */
    private function supportReasons(ScheduleState $state, array $types): array
    {
        $problem = $state->problem;
        $weeks = $problem->historyWeeks;
        $eligible = [];
        $reasons = [];

        foreach ($types as $type => $definition) {
            $eligible[$type] = array_keys(array_filter($problem->employees, fn (EmployeeSpec $e) => $this->eligibleFor($state, $e, $definition)));
        }

        foreach ($state->scopeIndexes() as $i) {
            foreach ($problem->employees as $employeeId => $employee) {
                $current = $state->shiftAt($employeeId, $i);

                if (! $current || $state->isFixed($employeeId, $i)) {
                    continue;
                }

                [$o, $s, , $f] = $current;
                $code = $problem->shifts[$s]->code;
                $isKid = $o !== null && isset($problem->outletParents[$o]);
                $type = match (true) {
                    $isKid => Problem::supportTypeKey($code, 'kid'),
                    (bool) $state->supportInterval($o, $s, $f) => Problem::supportTypeKey($code, 'lobby'),
                    default => null,
                };

                if ($type === null) {
                    continue;
                }

                $peers = array_values(array_diff($eligible[$type] ?? [], [$employeeId]));
                $past = $problem->supportHistory[$employeeId][$type] ?? 0;
                $peerAverage = $peers ? round(array_sum(array_map(fn ($e) => $problem->supportHistory[$e][$type] ?? 0, $peers)) / count($peers), 1) : null;
                $text = sprintf('%s được xếp %s: %d lượt %s trong %d tuần gần nhất', $employee->name, $type, $past, $type, $weeks)
                    . ($peerAverage !== null ? sprintf(' (trung bình %d người đủ điều kiện khác: %s)', count($peers), $peerAverage) : ' (là người duy nhất đủ điều kiện)')
                    . ', không OFF ngày này';

                if ($isKid && ! $problem->isHomeOutlet($employee, $o)) {
                    $text .= sprintf('; mượn từ %s vì %s không còn người phù hợp đi làm được ca này', $problem->outletName($employee->homeOutletId), $problem->outletName($problem->outletParents[$o]));
                } elseif (! $isKid) {
                    $text .= '; Lobby còn nhu cầu ở khung giờ này';
                }

                $reasons[] = ['date' => $state->dates[$i], 'employee_id' => $employeeId, 'type' => $type, 'text' => $text . '.'];
            }
        }

        return $reasons;
    }

    /**
     * Cảnh báo vận hành ca hỗ trợ: khung Lobby vượt định biên, Kid-Deline phải mượn người outlet khác.
     */
    private function supportWarnings(ScheduleState $state): array
    {
        $problem = $state->problem;
        $warnings = [];

        foreach ($state->coverage as $i => $windows) {
            foreach ($windows as $k => [$start, $end, , $target, $label]) {
                if (($have = $state->coverageCount($i, $k)) > $target) {
                    $warnings[] = ['code' => 'lobby_over', 'date' => $state->dates[$i], 'outlet_id' => $problem->stationOutletId, 'outlet_name' => $problem->outletName($problem->stationOutletId),
                        'message' => sprintf('%s %s %02d:%02d–%02d:%02d: %d người cùng lúc, định biên %d – có ca hỗ trợ dư (thường do ô đã khóa / xếp tay).',
                            $label, date('d/m', strtotime($state->dates[$i])), intdiv($start, 60), $start % 60, intdiv($end, 60), $end % 60, $have, $target)];
                }
            }
        }

        foreach ($state->scopeIndexes() as $i) {
            foreach ($problem->employees as $employee) {
                $current = $state->shiftAt($employee->id, $i);

                if ($current && $current[0] !== null && isset($problem->outletParents[$current[0]]) && ! $problem->isHomeOutlet($employee, $current[0])) {
                    $warnings[] = ['code' => 'kid_borrow', 'date' => $state->dates[$i], 'outlet_id' => $current[0], 'outlet_name' => $problem->outletName($current[0]),
                        'message' => sprintf('%s %s ca %s: mượn %s (%s) vì %s không còn người phù hợp đi làm được; %s vẫn đủ định biên tối thiểu.',
                            $problem->outletName($current[0]), date('d/m', strtotime($state->dates[$i])), $problem->shifts[$current[1]]->code,
                            $employee->name, $problem->outletName($employee->homeOutletId), $problem->outletName($problem->outletParents[$current[0]]),
                            $problem->outletName($employee->homeOutletId))];
                }
            }
        }

        return $warnings;
    }

    /**
     * Cảnh báo lệch quản lý ca sáng / chiều (outlet × ngày) kèm nguyên nhân, để người xếp lịch chủ động xử lý.
     */
    private function managerWarnings(ScheduleState $state): array
    {
        $problem = $state->problem;
        $warnings = [];

        foreach ($state->managerImbalances() as $row) {
            [$i, $date, $outletId] = [$row['i'], $row['date'], $row['outlet_id']];
            $managers = array_filter($problem->employees, fn (EmployeeSpec $e) => $e->isManager && $e->homeOutletId === $outletId);
            $off = count(array_filter($managers, fn (EmployeeSpec $e) => ! $state->shiftAt($e->id, $i)));
            $locked = count(array_filter($managers, fn (EmployeeSpec $e) => $state->isFixed($e->id, $i) && $state->shiftAt($e->id, $i)));
            $working = $row['morning'] + $row['afternoon'];

            $reason = match (true) {
                $working <= 1 => sprintf('chỉ có %d quản lý đi làm (%d OFF / nghỉ)', $working, $off),
                $locked > 0 => sprintf('%d ca quản lý đã khóa / xếp tay nên không chuyển được', $locked),
                default => 'giờ nghỉ giữa hai ca, số ngày làm liên tục hoặc định biên tối thiểu không cho chuyển thêm quản lý',
            };

            $warnings[] = [
                'code' => 'manager_imbalance',
                'date' => $date,
                'outlet_id' => $outletId,
                'outlet_name' => $problem->outletName($outletId),
                'message' => sprintf('%s %s: quản lý ca sáng %d / ca chiều %d – %s.', $problem->outletName($outletId),
                    date('d/m', strtotime($date)), $row['morning'], $row['afternoon'], $reason),
            ];
        }

        return $warnings;
    }

    private function explain(ScheduleState $state, string $status, array $hard, array $unfilled, array $metadata): array
    {
        $lines = [];
        $min = array_filter($unfilled, fn ($u) => $u['level'] === 'min');
        $target = array_filter($unfilled, fn ($u) => $u['level'] === 'target');

        $lines[] = match ($status) {
            SolveResult::FEASIBLE => 'Đã tạo lịch hợp lệ: không vi phạm ràng buộc cứng và đủ định biên tối thiểu ở mọi ca. Đây là phương án tốt nhất tìm được trong thời gian cho phép, không phải lời giải được chứng minh tối ưu.',
            SolveResult::PARTIAL => sprintf('Lịch không vi phạm ràng buộc cứng nhưng còn %d ô thiếu so với định biên tối thiểu. Thuật toán chưa chứng minh được là không thể đủ – xem phương án đề xuất từng ô.', count($min)),
            SolveResult::INFEASIBLE => $hard
                ? sprintf('Không có lịch hợp lệ: %d ô đã khóa/ngoài phạm vi đang vi phạm ràng buộc cứng. Cần sửa các ô này trước.', count($hard))
                : sprintf('Không thể đủ định biên tối thiểu ở %d ô: số người đủ điều kiện còn rảnh ít hơn nhu cầu. Lịch dưới đây là phương án hợp lệ tốt nhất.', count(array_filter($min, fn ($u) => $u['provably_impossible']))),
            SolveResult::TIMEOUT => 'Hết thời gian trước khi dựng xong phương án ban đầu. Hãy thu hẹp phạm vi hoặc tăng giới hạn thời gian.',
            default => 'Có lỗi khi xếp lịch.',
        };

        if ($target) {
            $lines[] = sprintf('%d ô chưa đạt định biên mục tiêu (đã đủ tối thiểu).', count($target));
        }

        $fairness = $this->fairnessSummary($state);

        if ($fairness) {
            $lines[] = sprintf('Giờ làm trong kỳ: thấp nhất %sh, cao nhất %sh, độ lệch chuẩn %sh. Ca nặng (nghỉ < %sh): %d lần, nhiều nhất %d lần/người.',
                $fairness['hours_min'], $fairness['hours_max'], $fairness['hours_stddev'],
                $state->problem->rules->softMinRestHours, $fairness['heavy_total'], $fairness['heavy_max_per_employee']);
        }

        if (! empty($metadata['time_limit_reached'])) {
            $lines[] = sprintf('Đã dừng tối ưu khi hết giới hạn %ss (%d bước thử, %d bước cải thiện).', $metadata['time_limit_seconds'], $metadata['iterations'] ?? 0, $metadata['improved'] ?? 0);
        }

        return $lines;
    }
}
