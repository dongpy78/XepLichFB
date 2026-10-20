@if (! $data->confirmed_at)
    <x-core::alert
        type="warning"
    >
        {!! BaseHelper::clean(
        trans('plugins/member::member.verify_email.notification', [
            'approve_link' => Html::link(
                route('member.verify-email', $data->id),
                trans('plugins/member::member.verify_email.approve_here'),
                ['class' => 'verify-member-email-button'],
            ),
        ])) !!}
    </x-core::alert>

    @push('footer')
        <x-core::modal
            id="verify-member-email-modal"
            type="warning"
            :title="trans('plugins/member::member.verify_email.confirm_heading')"
            button-id="confirm-verify-member-email-button"
            :button-label="trans('plugins/member::member.verify_email.confirm_button')"
        >
            {!! trans('plugins/member::member.verify_email.confirm_description') !!}
        </x-core::modal>
    @endpush
@endif
