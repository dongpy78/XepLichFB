<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\RuleRevision;
use Botble\RestaurantRoster\Services\SchedulingRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cấu hình quy tắc xếp lịch: Snack (Villa / Elprimo / tắt), hỗ trợ Lobby/Bar (công tắc tổng + từng ca). Quyền roster.settings.
 * Đổi cấu hình không sửa / xóa ô lịch nào; ô đang có thuộc nhóm bị tắt được liệt kê để quản lý xác nhận trước khi lưu.
 */
class SchedulingRuleController extends BaseController
{
    public function __construct(protected SchedulingRules $rules)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Cấu hình quy tắc xếp lịch', route('roster.rules.index'));
    }

    public function index()
    {
        $this->pageTitle('Cấu hình quy tắc xếp lịch');

        return view('plugins/restaurant-roster::rules.index', $this->viewData($this->rules->get()));
    }

    public function update(Request $request)
    {
        $codes = SchedulingRules::lobbyCodes();
        $data = $request->validate([
            'snack_mode' => ['required', Rule::in(['off', ...array_keys(SchedulingRules::snackOptions())])],
            'snack_count' => ['required', 'integer', 'min:1', 'max:20'],
            'lobby_enabled' => ['nullable', 'boolean'],
            'lobby_codes' => ['nullable', 'array'],
            'lobby_codes.*' => [Rule::in($codes)],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        // Công tắc tổng tắt: trình duyệt không gửi các công tắc con (bị khóa) – giữ nguyên lựa chọn từng ca để bật lại không mất.
        $enabled = $request->boolean('lobby_enabled');
        $new = $this->rules->normalize([
            'snack' => ['mode' => $data['snack_mode'], 'count' => (int) $data['snack_count']],
            'lobby' => ['enabled' => $enabled, 'codes' => $enabled
                ? array_map(fn ($code) => in_array($code, $data['lobby_codes'] ?? [], true), array_combine($codes, $codes))
                : $this->rules->get()['lobby']['codes']],
        ]);

        // Ô đang có (từ hôm nay) sẽ thuộc nhóm bị tắt mà trước đó chưa bị: hỏi lại trước khi lưu. Không ô nào bị sửa / xóa.
        $today = Carbon::today();
        $already = $this->rules->affected($this->rules->get(), $today)->pluck('id')->all();
        $newlyAffected = $this->rules->affected($new, $today)->reject(fn ($row) => in_array($row['id'], $already, true))->values();

        if ($newlyAffected->isNotEmpty() && ! $request->boolean('confirmed')) {
            $this->pageTitle('Cấu hình quy tắc xếp lịch');

            return view('plugins/restaurant-roster::rules.index', [...$this->viewData($new), 'pending' => $newlyAffected]);
        }

        $before = $this->rules->get();
        $this->rules->save($new, $request->user()?->getKey(), trim((string) ($request->user()?->name ?? '')) ?: null);
        $changes = $this->rules->describeChanges($before, $new);

        return $this
            ->httpResponse()
            ->setNextUrl(route('roster.rules.index'))
            ->setMessage($changes ? 'Đã lưu: ' . implode('; ', $changes) . '. Lịch đã xếp giữ nguyên – chạy lại xếp tự động để áp dụng.' : 'Không có thay đổi.');
    }

    private function viewData(array $rules): array
    {
        return [
            'rules' => $rules,
            'snackOptions' => SchedulingRules::snackOptions(),
            'snackShift' => (string) SchedulingRules::snackConfig('shift_code', 'M9'),
            'lobbyCodes' => SchedulingRules::lobbyCodes(),
            'activeSnack' => $this->rules->activeSnack($rules),
            'affected' => $this->rules->affected($this->rules->get(), Carbon::today()),
            'revisions' => RuleRevision::query()->latest('id')->limit(10)->get(),
            'pending' => null,
        ];
    }
}
