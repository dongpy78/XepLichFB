<?php

return [
    'new-reservation' => [
        'title' => 'Nieuwe Reservering Melding',
        'description' => 'E-mail verzonden naar de beheerder wanneer een nieuwe reservering wordt ingediend',
        'subject' => 'Nieuwe reservering van {{ reservation_name }}',
        'header' => 'Nieuwe Reservering Ontvangen!',
        'content' => '<p>U heeft een nieuwe reservering ontvangen met de volgende details:</p>
                     <p><strong>Naam:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefoon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tijd:</strong> {{ reservation_time }}<br>
                     <strong>Aantal Gasten:</strong> {{ number_of_guests }}<br>
                     <strong>Speciale Verzoeken:</strong> {{ special_requests }}</p>
                     <p>Log in op uw beheerpaneel om deze reservering te beheren.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Reservering Bevestiging',
        'description' => 'E-mail verzonden naar de klant na het indienen van een reservering',
        'subject' => 'Uw reserveringsbevestiging',
        'header' => 'Reservering Bevestiging',
        'content' => '<p>Beste {{ reservation_name }},</p>
                     <p>Bedankt voor uw reservering! We hebben uw boekingsverzoek ontvangen met de volgende details:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tijd:</strong> {{ reservation_time }}<br>
                     <strong>Aantal Gasten:</strong> {{ number_of_guests }}</p>
                     <p>We nemen binnenkort contact met u op om uw reservering te bevestigen. Als u vragen heeft, aarzel dan niet om contact met ons op te nemen.</p>
                     <p>Met vriendelijke groet,<br>Het Restaurant Team</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Reservering Status Update',
        'description' => 'E-mail verzonden naar de klant wanneer de reserveringsstatus wordt bijgewerkt',
        'subject' => 'Uw reserveringsstatus is bijgewerkt',
        'header' => 'Reservering Status Update',
        'content' => '<p>Beste {{ reservation_name }},</p>
                     <p>Uw reserveringsstatus is bijgewerkt naar: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Reserveringsdetails:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tijd:</strong> {{ reservation_time }}<br>
                     <strong>Aantal Gasten:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Als u vragen heeft, neem dan contact met ons op.</p>
                     <p>Met vriendelijke groet,<br>Het Restaurant Team</p>',
    ],
    'reservation_details' => 'Reserveringsdetails',
    'date' => 'Datum',
    'time' => 'Tijd',
    'guests' => 'Gasten',
    'special_requests' => 'Speciale Verzoeken',
    'visit_website' => 'Bezoek Onze Website',
    'view_reservation' => 'Bekijk Reservering',
    'cancel_or_modify' => 'Als u uw reservering wilt annuleren of wijzigen, neem dan contact met ons op via :restaurant_phone of :restaurant_email.',
    'contact_us' => 'Als u vragen heeft, neem dan contact met ons op via :restaurant_phone of :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Bevestigd',
    'cancelled' => 'Geannuleerd',
    'note_from_restaurant' => 'Opmerking van Restaurant',
    'make_new_reservation' => 'Nieuwe Reservering Maken',
    'status_update' => [
        'approved_title' => 'Reservering Bevestigd!',
        'approved_message' => 'Goed nieuws! Uw reservering is bevestigd.',
        'cancelled_title' => 'Reservering Geannuleerd',
        'cancelled_message' => 'Het spijt ons, maar uw reservering is geannuleerd.',
        'default_title' => 'Reservering Status Update',
        'default_message' => 'Uw reserveringsstatus is bijgewerkt.',
    ],
];
