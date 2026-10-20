<?php

namespace Botble\Member\Http\Requests\Fronts\Auth;

use Botble\Base\Rules\EmailRule;
use Botble\Member\Models\Member;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class RegisterRequest extends Request
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120', 'min:2'],
            'last_name' => ['required', 'string', 'max:120', 'min:2'],
            'email' => ['required', 'max:60', 'min:6', new EmailRule(), Rule::unique((new Member())->getTable())],
            'password' => ['required', 'min:6', 'confirmed'],
            'agree_terms_and_policy' => ['sometimes', 'accepted:1'],
        ];
    }
}
