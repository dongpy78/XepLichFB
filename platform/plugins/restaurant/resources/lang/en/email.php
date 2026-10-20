<?php

return [
    'new-reservation' => [
        'title' => 'New Reservation Notification',
        'description' => 'Email sent to admin when a new reservation is submitted',
        'subject' => 'New reservation from {{ reservation_name }}',
        'header' => 'New Reservation Received!',
        'content' => '<p>You have received a new reservation with the following details:</p>
                     <p><strong>Name:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Phone:</strong> {{ reservation_phone }}<br>
                     <strong>Date:</strong> {{ reservation_date }}<br>
                     <strong>Time:</strong> {{ reservation_time }}<br>
                     <strong>Number of Guests:</strong> {{ number_of_guests }}<br>
                     <strong>Special Requests:</strong> {{ special_requests }}</p>
                     <p>Please log in to your admin panel to manage this reservation.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Reservation Confirmation',
        'description' => 'Email sent to customer after submitting a reservation',
        'subject' => 'Your reservation confirmation',
        'header' => 'Reservation Confirmation',
        'content' => '<p>Dear {{ reservation_name }},</p>
                     <p>Thank you for your reservation! We have received your booking request with the following details:</p>
                     <p><strong>Date:</strong> {{ reservation_date }}<br>
                     <strong>Time:</strong> {{ reservation_time }}<br>
                     <strong>Number of Guests:</strong> {{ number_of_guests }}</p>
                     <p>We will contact you soon to confirm your reservation. If you have any questions, please don\'t hesitate to contact us.</p>
                     <p>Best regards,<br>The Restaurant Team</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Reservation Status Update',
        'description' => 'Email sent to customer when reservation status is updated',
        'subject' => 'Your reservation status has been updated',
        'header' => 'Reservation Status Update',
        'content' => '<p>Dear {{ reservation_name }},</p>
                     <p>Your reservation status has been updated to: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Reservation Details:</strong><br>
                     <strong>Date:</strong> {{ reservation_date }}<br>
                     <strong>Time:</strong> {{ reservation_time }}<br>
                     <strong>Number of Guests:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>If you have any questions, please contact us.</p>
                     <p>Best regards,<br>The Restaurant Team</p>',
    ],
    'reservation_details' => 'Reservation Details',
    'date' => 'Date',
    'time' => 'Time',
    'guests' => 'Guests',
    'special_requests' => 'Special Requests',
    'visit_website' => 'Visit Our Website',
    'view_reservation' => 'View Reservation',
    'cancel_or_modify' => 'If you need to cancel or modify your reservation, please contact us at :restaurant_phone or :restaurant_email.',
    'contact_us' => 'If you have any questions, please contact us at :restaurant_phone or :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
    'note_from_restaurant' => 'Note from Restaurant',
    'make_new_reservation' => 'Make New Reservation',
    'status_update' => [
        'approved_title' => 'Reservation Confirmed!',
        'approved_message' => 'Great news! Your reservation has been confirmed.',
        'cancelled_title' => 'Reservation Cancelled',
        'cancelled_message' => 'We\'re sorry, but your reservation has been cancelled.',
        'default_title' => 'Reservation Status Update',
        'default_message' => 'Your reservation status has been updated.',
    ],
];
