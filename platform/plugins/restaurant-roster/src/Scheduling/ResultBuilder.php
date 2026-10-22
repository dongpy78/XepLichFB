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

        $unfilled = [...$this->unfilled($state), ...$this->uncoveredWindows($state), ...$this->coreGaps($state)];
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

        $femaleStaffing = $this->femaleStaffing($state);
        $warnings = [...$this->managerWarnings($state), ...$this->supportWarnings($state), ...$this->femaleWarnings($femaleStaffing)];
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
            supportReport: [...$this->supportReport($state), 'female_staffing' => $femaleStaffing],
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
                $haveFemale = $state->femaleCount($i, $requirement->outletId, $requirement->shiftId);
                $positionGaps = [];

                foreach ($requirement->positionMins as $group => $need) {
                    if (($gap = $need - $state->positionCount($i, $requirement->outletId, $requirement->shiftId, $group)) > 0) {
                        $positionGaps[$problem->positionGroupLabel($group)] = $gap;
                    }
                }

                $minGap = max(0, $requirement->min - $have) + max(0, $requirement->minMale - $haveMale) + max(0, $requirement->minFemale - $haveFemale) + array_sum($positionGaps);
                $positionTargetGaps = 0;

                foreach (array_keys($requirement->positionMins + $requirement->positionTargets) as $group) {
                    $positionTargetGaps += max(0, $requirement->positionTarget($group) - $state->positionCount($i, $requirement->outletId, $requirement->shiftId, $group));
                }

                $targetGap = max(0, $requirement->target - $have) + max(0, $requirement->targetMale - $haveMale) + max(0, $requirement->targetFemale - $haveFemale) + $positionTargetGaps;

                if (! $minGap && ! $targetGap) {
                    continue;
                }

                $level = $minGap ? 'min' : 'target';
                $maleNeeded = $haveMale < ($level === 'min' ? $requirement->minMale : $requirement->targetMale);
                $femaleNeeded = ! $maleNeeded && $haveFemale < ($level === 'min' ? $requirement->minFemale : $requirement->targetFemale);
                $analysis = $this->analyse($state, $i, $requirement, $maleNeeded, $femaleNeeded);
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
                    'min_female' => $requirement->minFemale,
                    'target_female' => $requirement->targetFemale,
                    'assigned_female' => $haveFemale,
                    'missing_positions' => $positionGaps,
                    'missing' => $level === 'min' ? $minGap : $targetGap,
                    'eligible_total' => $analysis['eligible_total'],
                    'reasons' => $analysis['reasons'],
                    'provably_impossible' => $analysis['possible'] < ($maleNeeded ? $requirement->minMale : ($femaleNeeded ? $requirement->minFemale : $requirement->min)) && $level === 'min',
                    'proposals' => $this->proposals($state, $requirement, $level, $have, $analysis),
                ];
            }
        }

        usort($result, fn ($a, $b) => [$a['level'] === 'min' ? 0 : 1, $a['date'], $a['outlet_name'], $a['shift_code']] <=> [$b['level'] === 'min' ? 0 : 1, $b['date'], $b['outlet_name'], $b['shift_code']]);

        return $result;
    }

    /**
     * Đoạn giờ phủ Lobby chưa đủ người CÙNG LÚC (vd 14:00–16:45 trong khung 09:00–16:45), kèm ai đang có mặt,
     * nguyên nhân từng nữ waiter không lên được và phương án hợp lệ (giao (L), xếp OH(L) / M9 cho người đang OFF).
     */
    private function uncoveredWindows(ScheduleState $state): array
    {
        $problem = $state->problem;
        $result = [];

        foreach ($state->coverage as $i => $windows) {
            foreach ($windows as $k => [$start, $end, $min, $target, $label, , $femaleOnly, $group]) {
                $have = $state->coverageCount($i, $k);

                if ($have >= $target) {
                    continue;
                }

                $date = $state->dates[$i];
                [$windowStart, $windowEnd] = $state->coverageGroups[$i][$group];
                $level = $have < $min ? 'min' : 'target';
                $segment = LobbyCoverage::time($start) . '–' . LobbyCoverage::time($end);
                $window = $label . ' ' . $segment . ([$start, $end] === [$windowStart, $windowEnd] ? '' : ' (khung ' . LobbyCoverage::time($windowStart) . '–' . LobbyCoverage::time($windowEnd) . ')');
                $reasons = ['no_shift' => [], 'present' => [], 'support_candidates' => [], 'station_candidates' => [], 'source_minimum' => [], 'no_overlap' => [], 'approved_off' => [], 'locked' => [], 'blocked' => []];
                $proposals = [];
                $eligible = 0;

                // Ca có thể đưa một người lên Lobby trọn đoạn này (OH(L), M9 ở Lobby, M6(L), A2(L)…).
                $slots = array_filter($state->requirements[$i] ?? [], fn (Requirement $r) => in_array($k, $state->supportWindows($i, $r->outletId, $r->shiftId, $r->outletId !== $problem->stationOutletId), true));

                if (! $slots) {
                    $reasons['no_shift'][] = "Không ca nào có mặt ở Lobby trọn đoạn {$segment} – kiểm tra giờ ở Định biên quầy Lobby (khung chính 09:00–16:45) và khung (L) ở Mẫu ca";
                }

                foreach ($problem->employees as $employeeId => $employee) {
                    if (! $employee->canSupport($problem->stationOutletId, 0) && ! array_filter(array_keys($employee->skills), fn ($key) => str_starts_with($key, $problem->stationOutletId . ':'))) {
                        continue;
                    }

                    if ($femaleOnly && ! $employee->isFemaleStaff) {
                        continue;
                    }

                    $eligible++;
                    $name = $employee->name;
                    $current = $state->shiftAt($employeeId, $i);
                    $fixed = $state->fixed[$employeeId][$i] ?? null;

                    if ($current && in_array($k, $state->supportWindows($i, $current[0], $current[1], (bool) $current[3]), true)) {
                        $reasons['present'][] = $name . ' (' . $problem->shifts[$current[1]]->code . ($current[3] ? '(L)' : ' tại Lobby') . ')';
                    } elseif ($reason = $problem->hardUnavailable[$employeeId][$date] ?? null) {
                        $reasons['approved_off'][] = "{$name} ({$reason})";
                    } elseif ($fixed && ! $fixed->isWorking()) {
                        $reasons['locked'][] = "{$name} (ô đã khóa: OFF)";
                    } elseif ($current && in_array($k, $state->supportWindows($i, $current[0], $current[1], true), true)) {
                        $text = sprintf('%s đang làm %s tại %s', $name, $problem->shifts[$current[1]]->code, $problem->outletName($current[0]));
                        $requirement = $state->requirements[$i]["{$current[0]}|{$current[1]}"] ?? null;

                        if ($fixed) {
                            $reasons['locked'][] = "{$text} (ô đã khóa)";
                        } elseif ($state->removalBreaksMinimum($employeeId, $i, $current[0], $current[1]) && ! $state->supportCountsAtOutlet($requirement, $current[1])) {
                            $reasons['source_minimum'][] = $text . ' – ' . $problem->outletName($current[0]) . ' ' . $problem->shifts[$current[1]]->code . ' sẽ dưới tối thiểu';
                        } else {
                            $reasons['support_candidates'][] = $text;
                        }
                    } elseif ($current) {
                        $reasons['no_overlap'][] = sprintf('%s đang làm %s tại %s – không có mặt ở Lobby %s', $name, $problem->shifts[$current[1]]->code, $problem->outletName($current[0]), $segment);
                    } else {
                        $options = [];

                        foreach ($slots as $slot) {
                            $atStation = $slot->outletId === $problem->stationOutletId;

                            if ($employee->skillLevel($slot->outletId, $slot->shiftId) !== null && ! $state->violation($employeeId, $i, $slot->outletId, $slot->shiftId, support: ! $atStation)) {
                                $options[] = $problem->shifts[$slot->shiftId]->code . ($atStation ? '' : '(L)');
                            }
                        }

                        if ($options) {
                            $reasons['station_candidates'][] = $name . ' – ' . implode('/', array_unique($options));
                        } else {
                            $reasons['blocked'][] = "{$name} (OFF theo lịch – không ca Lobby nào hợp lệ: khu vực sống, giờ nghỉ, số ngày làm)";
                        }
                    }
                }

                foreach (array_slice($reasons['support_candidates'], 0, 3) as $text) {
                    $proposals[] = ['date' => $date, 'type' => 'support', 'label' => 'A', 'text' => "Giao hỗ trợ Lobby (L): {$text}."];
                }

                if ($reasons['station_candidates']) {
                    $proposals[] = ['date' => $date, 'type' => 'station_shift', 'label' => 'B',
                        'text' => 'Xếp thêm ca phủ Lobby cho người đang OFF và đồng ý: ' . implode('; ', array_slice($reasons['station_candidates'], 0, 5)) . '.'];
                }

                $proposals[] = ['date' => $date, 'type' => 'keep_warning', 'label' => 'D', 'text' => "Giữ cảnh báo: Lobby {$segment} chỉ có {$have} người cùng lúc – quản lý chủ động xử lý."];

                $result[] = [
                    'date' => $date,
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
                    'provably_impossible' => ! $reasons['support_candidates'] && ! $reasons['station_candidates'],
                    'lobby' => ['segment_start' => $start, 'segment_end' => $end, 'window_start' => $windowStart, 'window_end' => $windowEnd, 'female_only' => $femaleOnly],
                    'proposals' => $proposals,
                ];
            }
        }

        return $result;
    }

    /**
     * Ngày Night không có nhân viên Night cứng nào để điều phối (bắt buộc ≥ 1 / ngày), kèm lý do từng người Night cứng.
     */
    private function coreGaps(ScheduleState $state): array
    {
        $problem = $state->problem;
        $result = [];

        foreach ($state->coreShortDays() as [$i, $outletId]) {
            $date = $state->dates[$i];
            $reasons = ['approved_off' => [], 'locked' => [], 'busy_elsewhere' => [], 'blocked' => []];
            $movable = 0;

            foreach ($problem->employees as $employeeId => $employee) {
                if (! $employee->isNightCore()) {
                    continue;
                }

                $fixed = $state->fixed[$employeeId][$i] ?? null;

                if ($reason = $problem->hardUnavailable[$employeeId][$date] ?? null) {
                    $reasons['approved_off'][] = "{$employee->name} ({$reason})";
                } elseif ($fixed && ! $fixed->isWorking()) {
                    $reasons['locked'][] = "{$employee->name} (ô đã khóa: OFF)";
                } elseif ($state->shiftAt($employeeId, $i) === null) {
                    $reasons['blocked'][] = "{$employee->name} (OFF theo lịch – đủ số ngày làm trong tuần; đổi ngày OFF với người Night cứng còn lại)";
                    $movable++;
                } else {
                    $reasons['busy_elsewhere'][] = "{$employee->name} (đang làm ca khác)";
                    $movable++;
                }
            }

            $result[] = [
                'date' => $date, 'outlet_id' => $outletId, 'outlet_name' => $problem->outletName($outletId), 'shift_id' => null,
                'shift_code' => 'Night cứng', 'level' => 'min', 'min' => 1, 'target' => 1, 'min_male' => 0, 'target_male' => 0,
                'assigned' => 0, 'assigned_male' => 0, 'missing' => 1, 'eligible_total' => count(array_filter($problem->employees, fn ($e) => $e->isNightCore())),
                'reasons' => $reasons, 'provably_impossible' => $movable === 0,
                'proposals' => [['date' => $date, 'type' => 'keep_warning', 'label' => 'D',
                    'text' => 'Night ngày ' . date('d/m', strtotime($date)) . ' không có nhân viên Night cứng điều phối – đổi ngày OFF của một người Night cứng hoặc duyệt ngoại lệ (ghi lý do).']],
            ];
        }

        return $result;
    }

    private function analyse(ScheduleState $state, int $i, Requirement $requirement, bool $maleNeeded, bool $femaleNeeded = false): array
    {
        $problem = $state->problem;
        $date = $state->dates[$i];
        $reasons = ['approved_off' => [], 'locked' => [], 'supporting' => [], 'busy_elsewhere' => [], 'blocked' => [], 'gender_missing' => [], 'overtime_possible' => [], 'transferable' => []];
        $eligible = 0;
        $possible = 0;

        foreach ($problem->employees as $employeeId => $employee) {
            if ($employee->skillLevel($requirement->outletId, $requirement->shiftId) === null) {
                continue;
            }

            // Thiếu nữ waiter: chỉ xét nữ waiter thông thường (không tính cấp quản lý, vị trí khác).
            if ($femaleNeeded && ! $employee->isFemaleStaff) {
                continue;
            }

            if ($maleNeeded && ! $employee->countsAsMale()) {
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
                    // Cùng ca, cùng outlet nhưng đang hỗ trợ Lobby (L): không tính vào outlet trong ca này.
                    if ($current[3] && ($window = $problem->shifts[$s]->lobbyWindow)) {
                        $lobbyShort = array_filter($state->supportWindows($i, $o, $s, true), fn ($k) => $state->coverageCount($i, $k) - 1 < $state->coverage[$i][$k][2]);
                        $reasons['supporting'][] = sprintf('%s đang %s(L) – ở Lobby %02d:%02d–%02d:%02d%s', $name, $problem->shifts[$s]->code,
                            intdiv($window[0], 60) % 24, $window[0] % 60, intdiv($window[1], 60) % 24, $window[1] % 60,
                            $lobbyShort ? ', không rút về được vì Lobby sẽ thiếu tối thiểu' . ($fixed ? ' (ô đã khóa)' : '') : ($fixed ? ' (ô đã khóa)' : ', có thể đổi người hỗ trợ'));
                    }

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
                $sourceFemale = $state->femaleCount($i, (int) $o, $s);
                $keepsSourceMin = ! $source || ($sourceCount - 1 >= $source->min && $sourceMale - ($employee->countsAsMale() ? 1 : 0) >= $source->minMale
                    && $sourceFemale - ($employee->isFemaleStaff ? 1 : 0) >= $source->minFemale);

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
     * Nữ waiter làm trực tiếp tại outlet theo ngày × outlet × ca (sau khi đã xếp Lobby và Kid-Deline):
     * số người trực tiếp, tối thiểu / mục tiêu (cấu hình định biên), số người đang hỗ trợ Lobby (L), đứng Kid-Deline,
     * điều sang outlet khác trong ca, tình trạng thiếu / vượt và nguyên nhân.
     */
    private function femaleStaffing(ScheduleState $state): array
    {
        $problem = $state->problem;
        $rows = [];
        $staff = array_filter($problem->employees, fn (EmployeeSpec $e) => $e->isFemaleStaff);

        foreach ($state->requirements as $i => $requirements) {
            foreach ($requirements as $requirement) {
                $o = $requirement->outletId;
                $s = $requirement->shiftId;

                if ($o === $problem->stationOutletId || isset($problem->outletParents[$o])
                    || ! in_array($problem->shifts[$s]->category(), ['morning', 'afternoon'], true)) {
                    continue;
                }

                $support = 0;
                $kid = 0;
                $away = 0;
                $home = 0;

                foreach ($staff as $employee) {
                    if ($employee->homeOutletId !== $o) {
                        continue;
                    }

                    $home++;
                    $current = $state->shiftAt($employee->id, $i);

                    if (! $current || $current[1] !== $s) {
                        continue;
                    }

                    match (true) {
                        $current[0] === $o && $current[3] => $support++,
                        $current[0] !== null && isset($problem->outletParents[$current[0]]) => $kid++,
                        $current[0] !== $o => $away++,
                        default => null,
                    };
                }

                if (! $home && ! $requirement->minFemale && ! $requirement->targetFemale) {
                    continue;
                }

                $direct = $state->femaleCount($i, $o, $s);
                $rows[] = [
                    'date' => $state->dates[$i],
                    'outlet_id' => $o,
                    'outlet_name' => $problem->outletName($o),
                    'shift_code' => $problem->shifts[$s]->code,
                    'direct' => $direct,
                    'min' => $requirement->minFemale,
                    'target' => $requirement->targetFemale,
                    'lobby' => $support,
                    'kid' => $kid,
                    'away' => $away,
                    'status' => match (true) {
                        $direct < $requirement->minFemale => 'short_min',
                        $direct < $requirement->targetFemale => 'short_target',
                        $requirement->targetFemale > 0 && $direct > $requirement->targetFemale => 'over',
                        default => 'ok',
                    },
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['outlet_name'], $a['shift_code']] <=> [$b['date'], $b['outlet_name'], $b['shift_code']]);

        return $rows;
    }

    /**
     * Cảnh báo: ca vượt mục tiêu nữ waiter trong khi ca kia của cùng outlet hôm đó còn thiếu – người dư không chuyển được
     * (giờ nghỉ, ca khóa, OFF) hoặc nên được quản lý cân nhắc chuyển.
     */
    private function femaleWarnings(array $rows): array
    {
        $warnings = [];
        $byDay = [];

        foreach ($rows as $row) {
            $byDay[$row['date'] . '|' . $row['outlet_id']][] = $row;
        }

        foreach ($byDay as $pair) {
            foreach ($pair as $over) {
                if ($over['status'] !== 'over') {
                    continue;
                }

                foreach ($pair as $short) {
                    if (in_array($short['status'], ['short_min', 'short_target'], true)) {
                        $warnings[] = ['code' => 'female_unbalanced', 'date' => $over['date'], 'outlet_id' => $over['outlet_id'], 'outlet_name' => $over['outlet_name'],
                            'message' => sprintf('%s %s: %s có %d nữ waiter trực tiếp (mục tiêu %d) trong khi %s chỉ %d (tối thiểu %d, mục tiêu %d) – người dư không chuyển được do giờ nghỉ, ca đã khóa hoặc định biên khác; quản lý cân nhắc đổi ca.',
                                $over['outlet_name'], date('d/m', strtotime($over['date'])), $over['shift_code'], $over['direct'], $over['target'],
                                $short['shift_code'], $short['direct'], $short['min'], $short['target'])];
                    }
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
