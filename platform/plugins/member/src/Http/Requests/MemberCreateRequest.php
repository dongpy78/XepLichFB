<?php

namespace Botble\Member\Http\Requests;

use Botble\Base\Rules\EmailRule;
use Botble\Member\Models\Member;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class MemberCreateRequest extends Request
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120', 'min:2'],
            'last_name' => ['required', 'string', 'max:120', 'min:2'],
            'email' => [
                'required',
                'max:60',
                'min:6',
                new EmailRule(),
                Rule::unique((new Member())->getTable(), 'email'),
            ],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }
}
