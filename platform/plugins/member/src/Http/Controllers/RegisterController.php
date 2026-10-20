<?php

namespace Botble\Member\Http\Controllers;

use Botble\ACL\Traits\RegistersUsers;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Member\Forms\Fronts\Auth\RegisterForm;
use Botble\Member\Http\Requests\Fronts\Auth\RegisterRequest;
use Botble\Member\Models\Member;
use Botble\SeoHelper\Facades\SeoHelper;
use Botble\Theme\Facades\Theme;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

class RegisterController extends BaseController
{
    use RegistersUsers;

    protected string $redirectTo = '/';

    public function showRegistrationForm()
    {
        abort_unless(setting('member_enabled_registration', true), 404);

        SeoHelper::setTitle(__('Register'));

        if (! session()->has('url.intended')) {
            session(['url.intended' => url()->previous()]);
        }

        Theme::breadcrumb()->add(__('Register'), route('public.member.register'));

        return Theme::scope(
            'member.auth.register',
            ['form' => RegisterForm::create()],
            'plugins/member::themes.auth.register'
        )->render();
    }

    public function confirm(int|string $id, Request $request)
    {
        abort_unless(setting('member_enabled_registration', true), 404);

        abort_unless(URL::hasValidSignature($request), 404);

        $member = Member::query()->findOrFail($id);

        $member->confirmed_at = Carbon::now();
        $member->save();

        $this->guard()->login($member);

        return $this
            ->httpResponse()
            ->setNextRoute('public.member.dashboard')
            ->setMessage(trans('plugins/member::member.confirmation_successful'));
    }

    protected function guard()
    {
        return auth('member');
    }

    public function resendConfirmation(Request $request)
    {
        abort_unless(setting('member_enabled_registration', true), 404);

        /**
         * @var Member $member
         */
        $member = Member::query()->where('email', $request->input('email'))->first();

        if (! $member) {
            return $this
                ->httpResponse()
                ->setError()
                ->setMessage(__('Cannot find this account!'));
        }

        $this->sendConfirmationToUser($member);

        return $this
            ->httpResponse()
            ->setMessage(trans('plugins/member::member.confirmation_resent'));
    }

    protected function sendConfirmationToUser(Member $member)
    {
        // Notify the user
        $notificationConfig = config('plugins.member.general.notification');
        if ($notificationConfig) {
            $notification = app($notificationConfig);
            $member->notify($notification);
        }
    }

    public function register(RegisterRequest $request)
    {
        abort_unless(setting('member_enabled_registration', true), 404);

        $form = RegisterForm::create();

        $member = null;

        $form->saving(function (RegisterForm $form) use (&$member): void {
            /**
             * @var Member $member
             */
            $member = $this->create($form->getRequest()->input());
            $form->setModel($member);

            event(new Registered($member));
        });

        if (setting('verify_account_email', config('plugins.member.general.verify_email'))) {
            $this->sendConfirmationToUser($member);

            $this->registered($request, $member);

            $message = __('We have sent you an email to verify your email. Please check and confirm your email address!');

            return $this
                ->httpResponse()
                ->setNextUrl(route('public.member.login'))
                ->with(['auth_warning_message' => $message])
                ->setMessage($message);
        }

        $member->confirmed_at = Carbon::now();
        $member->save();

        $this->guard()->login($member);

        $this->registered($request, $member);

        return $this
            ->httpResponse()
            ->setNextUrl($this->redirectPath());
    }

    protected function create(array $data)
    {
        return Member::query()->forceCreate([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
