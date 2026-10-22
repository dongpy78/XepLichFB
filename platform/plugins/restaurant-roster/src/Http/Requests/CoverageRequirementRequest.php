<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;

class CoverageRequirementRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'weekday' => $this->input('weekday') ?: null,
            'date' => $this->input('date') ?: null,
            'shift_codes' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('shift_codes'))) ?: null,
            'station' => $this->input('station') ?: config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY'),
        ]);
    }

    /**
     * Khung định biên Lobby phải phủ được: mọi đoạn giờ trong khung phải có ít nhất một ca có mặt ở Lobby
     * (ca làm trọn ở Lobby như M9, hoặc khung (L) của ca như OH(L), M6(L), A2(L) – xem Mẫu ca). Không thì ngày nào cũng thiếu.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $minutes = fn ($time) => (int) substr((string) $time, 0, 2) * 60 + (int) substr((string) $time, 3, 2);
            [$start, $end] = [$minutes($this->input('start_time')), $minutes($this->input('end_time'))];
            $codes = array_filter(explode(',', (string) $this->input('shift_codes')));
            $builder = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class);
            $presence = [];

            foreach (\Botble\RestaurantRoster\Models\ShiftTemplate::query()->wherePublished()->get() as $template) {
                if ($codes && ! in_array(strtoupper((string) $template->code), $codes, true)) {
                    continue;
                }

                $spec = $builder->shiftSpec($template);

                if ($template->support_station === $this->input('station')) {
                    $presence[$spec->code] = [$spec->start(), $spec->end()];
                } elseif ($spec->lobbyWindow) {
                    $presence[$spec->code . '(L)'] = $spec->lobbyWindow;
                }
            }

            $gaps = [];

            foreach (\Botble\RestaurantRoster\Scheduling\LobbyCoverage::segments($start, $end, array_values($presence)) as [$from, $to]) {
                if (! array_filter($presence, fn ($interval) => \Botble\RestaurantRoster\Scheduling\LobbyCoverage::covers($interval, $from, $to))) {
                    $gaps[] = \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($from) . '–' . \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($to);
                }
            }

            if ($gaps && (int) $this->input('min_staff') > 0) {
                $list = implode(', ', array_map(fn ($code, $i) => $code . ' ' . \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($i[0]) . '–' . \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($i[1]), array_keys($presence), $presence));
                $validator->errors()->add('start_time', 'Không ca nào có mặt ở Lobby trong ' . implode(', ', $gaps)
                    . ' – ngày nào cũng sẽ thiếu người. Giờ có mặt ở Lobby theo Mẫu ca: ' . ($list ?: 'chưa có') . '. Khung chính theo quy tắc: 09:00–16:45.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'station' => ['required', 'string', 'max:20'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'shift_codes' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9]+(,[A-Z0-9]+)*$/'],
            'min_staff' => ['required', 'integer', 'min:0', 'max:20'],
            'target_staff' => ['required', 'integer', 'min:0', 'max:20', 'gte:min_staff'],
            'is_confirmed' => ['required', 'boolean'],
            'female_only' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'start_time' => 'Từ giờ', 'end_time' => 'Đến giờ', 'min_staff' => 'Tối thiểu', 'target_staff' => 'Mục tiêu',
            'weekday' => 'Thứ', 'date' => 'Ngày cụ thể', 'shift_codes' => 'Chỉ tính ca', 'female_only' => 'Đếm',
        ];
    }
}
