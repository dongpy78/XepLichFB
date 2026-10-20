<?php

return [
    'title' => 'Member',
    'description' => 'View and update member settings',
    'verify_account_email' => "Verify account's email?",
    'verify_account_email_helper' => "When it's enabled, a verification link will be sent to account's email, customers need to click on this link to verify their email before they can log in. Need to config email in Admin -> Settings -> Email to send email verification.",
    'enabled_login' => 'Allow visitors to login?',
    'enabled_login_helper' => 'When it is enabled, visitors can log in to your site if they have an account.',
    'enabled_registration' => 'Allow visitors to register account?',
    'enabled_registration_helper' => 'When it is enabled, visitors can register an account on your site.',
    'email' => [
        'templates' => [
            'confirm_email' => [
                'title' => 'Confirm email',
                'description' => 'Send email to user when they register an account to verify their email',
                'subject' => 'Confirm Email Notification',
                'verify_link' => 'Verify email link',
                'member_name' => 'Member name',
            ],
            'password_reminder' => [
                'title' => 'Reset password',
                'description' => 'Send email to user when requesting reset password',
                'subject' => 'Reset Password',
                'reset_link' => 'Reset password link',
            ],
            'new_pending_post' => [
                'title' => 'New pending post',
                'description' => 'Send email to admin when a new post created',
                'subject' => 'New post is pending on {{ site_title }} by {{ post_author }}',
                'post_author' => 'Post Author',
                'post_name' => 'Post Name',
                'post_url' => 'Post URL',
            ],
        ],
    ],
];
