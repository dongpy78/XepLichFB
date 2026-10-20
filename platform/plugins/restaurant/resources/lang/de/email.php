<?php

return [
    'new-reservation' => [
        'title' => 'Neue Reservierungsbenachrichtigung',
        'description' => 'E-Mail, die an den Administrator gesendet wird, wenn eine neue Reservierung eingereicht wird',
        'subject' => 'Neue Reservierung von {{ reservation_name }}',
        'header' => 'Neue Reservierung erhalten!',
        'content' => '<p>Sie haben eine neue Reservierung mit folgenden Details erhalten:</p>
                     <p><strong>Name:</strong> {{ reservation_name }}<br>
                     <strong>E-Mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Uhrzeit:</strong> {{ reservation_time }}<br>
                     <strong>Anzahl der Gäste:</strong> {{ number_of_guests }}<br>
                     <strong>Besondere Wünsche:</strong> {{ special_requests }}</p>
                     <p>Bitte melden Sie sich in Ihrem Admin-Panel an, um diese Reservierung zu verwalten.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Reservierungsbestätigung',
        'description' => 'E-Mail, die nach dem Absenden einer Reservierung an den Kunden gesendet wird',
        'subject' => 'Ihre Reservierungsbestätigung',
        'header' => 'Reservierungsbestätigung',
        'content' => '<p>Sehr geehrte/r {{ reservation_name }},</p>
                     <p>Vielen Dank für Ihre Reservierung! Wir haben Ihre Buchungsanfrage mit folgenden Details erhalten:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Uhrzeit:</strong> {{ reservation_time }}<br>
                     <strong>Anzahl der Gäste:</strong> {{ number_of_guests }}</p>
                     <p>Wir werden uns bald mit Ihnen in Verbindung setzen, um Ihre Reservierung zu bestätigen. Wenn Sie Fragen haben, zögern Sie bitte nicht, uns zu kontaktieren.</p>
                     <p>Mit freundlichen Grüßen,<br>Das Restaurant-Team</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Reservierungsstatus-Aktualisierung',
        'description' => 'E-Mail, die an den Kunden gesendet wird, wenn der Reservierungsstatus aktualisiert wird',
        'subject' => 'Ihr Reservierungsstatus wurde aktualisiert',
        'header' => 'Reservierungsstatus-Aktualisierung',
        'content' => '<p>Sehr geehrte/r {{ reservation_name }},</p>
                     <p>Ihr Reservierungsstatus wurde aktualisiert auf: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Reservierungsdetails:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Uhrzeit:</strong> {{ reservation_time }}<br>
                     <strong>Anzahl der Gäste:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Wenn Sie Fragen haben, kontaktieren Sie uns bitte.</p>
                     <p>Mit freundlichen Grüßen,<br>Das Restaurant-Team</p>',
    ],
    'reservation_details' => 'Reservierungsdetails',
    'date' => 'Datum',
    'time' => 'Uhrzeit',
    'guests' => 'Gäste',
    'special_requests' => 'Besondere Wünsche',
    'visit_website' => 'Besuchen Sie unsere Website',
    'view_reservation' => 'Reservierung ansehen',
    'cancel_or_modify' => 'Wenn Sie Ihre Reservierung stornieren oder ändern möchten, kontaktieren Sie uns bitte unter :restaurant_phone oder :restaurant_email.',
    'contact_us' => 'Wenn Sie Fragen haben, kontaktieren Sie uns bitte unter :restaurant_phone oder :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Bestätigt',
    'cancelled' => 'Storniert',
    'note_from_restaurant' => 'Notiz vom Restaurant',
    'make_new_reservation' => 'Neue Reservierung vornehmen',
    'status_update' => [
        'approved_title' => 'Reservierung bestätigt!',
        'approved_message' => 'Gute Nachrichten! Ihre Reservierung wurde bestätigt.',
        'cancelled_title' => 'Reservierung storniert',
        'cancelled_message' => 'Es tut uns leid, aber Ihre Reservierung wurde storniert.',
        'default_title' => 'Reservierungsstatus-Aktualisierung',
        'default_message' => 'Ihr Reservierungsstatus wurde aktualisiert.',
    ],
];
