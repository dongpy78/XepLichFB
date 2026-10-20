<?php

return [
    'new-reservation' => [
        'title' => 'Ny reservationsmeddelelse',
        'description' => 'E-mail sendt til administrator, når en ny reservation er indsendt',
        'subject' => 'Ny reservation fra {{ reservation_name }}',
        'header' => 'Ny reservation modtaget!',
        'content' => '<p>Du har modtaget en ny reservation med følgende detaljer:</p>
                     <p><strong>Navn:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gæster:</strong> {{ number_of_guests }}<br>
                     <strong>Særlige ønsker:</strong> {{ special_requests }}</p>
                     <p>Log venligst ind på dit administratorpanel for at håndtere denne reservation.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Reservationsbekræftelse',
        'description' => 'E-mail sendt til kunden efter indsendelse af en reservation',
        'subject' => 'Din reservationsbekræftelse',
        'header' => 'Reservationsbekræftelse',
        'content' => '<p>Kære {{ reservation_name }},</p>
                     <p>Tak for din reservation! Vi har modtaget din bestilling med følgende detaljer:</p>
                     <p><strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gæster:</strong> {{ number_of_guests }}</p>
                     <p>Vi vil snart kontakte dig for at bekræfte din reservation. Hvis du har spørgsmål, er du velkommen til at kontakte os.</p>
                     <p>Venlig hilsen,<br>Restaurantteamet</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Opdatering af reservationsstatus',
        'description' => 'E-mail sendt til kunden, når reservationsstatus opdateres',
        'subject' => 'Din reservationsstatus er blevet opdateret',
        'header' => 'Opdatering af reservationsstatus',
        'content' => '<p>Kære {{ reservation_name }},</p>
                     <p>Din reservationsstatus er blevet opdateret til: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Reservationsdetaljer:</strong><br>
                     <strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gæster:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Hvis du har spørgsmål, bedes du kontakte os.</p>
                     <p>Venlig hilsen,<br>Restaurantteamet</p>',
    ],
    'reservation_details' => 'Reservationsdetaljer',
    'date' => 'Dato',
    'time' => 'Tid',
    'guests' => 'Gæster',
    'special_requests' => 'Særlige ønsker',
    'visit_website' => 'Besøg vores hjemmeside',
    'view_reservation' => 'Se reservation',
    'cancel_or_modify' => 'Hvis du skal annullere eller ændre din reservation, bedes du kontakte os på :restaurant_phone eller :restaurant_email.',
    'contact_us' => 'Hvis du har spørgsmål, bedes du kontakte os på :restaurant_phone eller :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Bekræftet',
    'cancelled' => 'Annulleret',
    'note_from_restaurant' => 'Note fra restaurant',
    'make_new_reservation' => 'Lav ny reservation',
    'status_update' => [
        'approved_title' => 'Reservation bekræftet!',
        'approved_message' => 'Gode nyheder! Din reservation er blevet bekræftet.',
        'cancelled_title' => 'Reservation annulleret',
        'cancelled_message' => 'Vi beklager, men din reservation er blevet annulleret.',
        'default_title' => 'Opdatering af reservationsstatus',
        'default_message' => 'Din reservationsstatus er blevet opdateret.',
    ],
];
