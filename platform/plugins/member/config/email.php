<?php

return [
    'name' => 'plugins/member::member.settings.email.title',
    'description' => 'plugins/member::member.settings.email.description',
    'templates' => [
        'confirm-email' => [
            'title' => 'plugins/member::settings.email.templates.confirm_email.title',
            'description' => 'plugins/member::settings.email.templates.confirm_email.description',
            'subject' => 'plugins/member::settings.email.templates.confirm_email.subject',
            'can_off' => false,
            'variables' => [
                'verify_link' => 'plugins/member::settings.email.templates.confirm_email.verify_link',
                'member_name' => 'plugins/member::settings.email.templates.confirm_email.member_name',
            ],
        ],
        'password-reminder' => [
            'title' => 'plugins/member::settings.email.templates.password_reminder.title',
            'description' => 'plugins/member::settings.email.templates.password_reminder.description',
            'subject' => 'plugins/member::settings.email.templates.password_reminder.subject',
            'can_off' => false,
            'variables' => [
                'reset_link' => 'plugins/member::settings.email.templates.password_reminder.reset_link',
            ],
        ],
        'new-pending-post' => [
            'title' => 'plugins/member::settings.email.templates.new_pending_post.title',
            'description' => 'plugins/member::settings.email.templates.new_pending_post.description',
            'subject' => 'plugins/member::settings.email.templates.new_pending_post.subject',
            'can_off' => true,
            'variables' => [
                'post_author' => 'plugins/member::settings.email.templates.new_pending_post.post_author',
                'post_name' => 'plugins/member::settings.email.templates.new_pending_post.post_name',
                'post_url' => 'plugins/member::settings.email.templates.new_pending_post.post_url',
            ],
        ],
    ],
];
