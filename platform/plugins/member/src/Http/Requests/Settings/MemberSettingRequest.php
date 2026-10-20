<?php

namespace Botble\Member\Http\Requests\Settings;

use Botble\Base\Rules\OnOffRule;
use Botble\Support\Http\Requests\Request;

class MemberSettingRequest extends Request
{
    public function rules(): array
    {
        return [
            'member_enabled_login' => [new OnOffRule()],
            'member_enabled_registration' => [new OnOffRule()],
            'verify_account_email' => [new OnOffRule()],
        ];
    }
}
