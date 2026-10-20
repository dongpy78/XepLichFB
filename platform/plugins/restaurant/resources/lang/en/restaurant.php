<?php

return [
    'menu_name' => 'Restaurant',
    'name' => 'Restaurant',
    'booking_calendar' => 'Booking Calendar',
    'reservation_detail' => 'Reservation Details',
    'calendar_tip_title' => 'Quick Tip:',
    'calendar_tip_message' => 'Click on any date in the calendar to quickly create a new reservation for that day. You can also click on existing reservations to view or edit their details.',
    'form' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'reservation_date' => 'Reservation Date',
        'reservation_time' => 'Reservation Time',
        'guests' => 'Guests',
        'status' => 'Status',
        'special_requests' => 'Special Requests',
    ],
    'settings' => [
        'title' => 'Settings',
        'description' => 'Configure restaurant settings and preferences',
        'menu_title' => 'Settings',
        'reservation_time_format' => 'Reservation Time Format',
        '12_hours_format' => '12 Hours (AM/PM)',
        '24_hours_format' => '24 Hours',
        'reservation_time_format_helper' => 'Choose the time format for reservation time selection',
        'email' => [
            'templates' => [
                'title' => 'Restaurant Email Templates',
                'description' => 'Configure email templates for restaurant reservations and notifications',
            ],
        ],
    ],
    'not_available' => 'N/A',
    'delete_confirm_title' => 'Confirm Delete',
    'delete_confirm_message' => 'Are you sure you want to delete this reservation? This action cannot be undone.',
    'delete' => 'Delete',
];
