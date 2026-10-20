<?php

return [
    'new-reservation' => [
        'title' => 'Oznámenie o novej rezervácii',
        'description' => 'E-mail odoslaný administrátorovi pri odoslaní novej rezervácie',
        'subject' => 'Nová rezervácia od {{ reservation_name }}',
        'header' => 'Nová rezervácia prijatá!',
        'content' => '<p>Obdržali ste novú rezerváciu s nasledujúcimi údajmi:</p>
                     <p><strong>Meno:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefón:</strong> {{ reservation_phone }}<br>
                     <strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostí:</strong> {{ number_of_guests }}<br>
                     <strong>Špeciálne požiadavky:</strong> {{ special_requests }}</p>
                     <p>Pre správu tejto rezervácie sa prosím prihláste do administračného panelu.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potvrdenie rezervácie',
        'description' => 'E-mail odoslaný zákazníkovi po odoslaní rezervácie',
        'subject' => 'Potvrdenie vašej rezervácie',
        'header' => 'Potvrdenie rezervácie',
        'content' => '<p>Vážený/á {{ reservation_name }},</p>
                     <p>Ďakujeme za vašu rezerváciu! Obdržali sme váš požiadavok na rezerváciu s nasledujúcimi údajmi:</p>
                     <p><strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostí:</strong> {{ number_of_guests }}</p>
                     <p>Čoskoro vás budeme kontaktovať pre potvrdenie vašej rezervácie. Ak máte akékoľvek otázky, neváhajte nás kontaktovať.</p>
                     <p>S pozdravom,<br>Tím reštaurácie</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Aktualizácia stavu rezervácie',
        'description' => 'E-mail odoslaný zákazníkovi pri aktualizácii stavu rezervácie',
        'subject' => 'Stav vašej rezervácie bol aktualizovaný',
        'header' => 'Aktualizácia stavu rezervácie',
        'content' => '<p>Vážený/á {{ reservation_name }},</p>
                     <p>Stav vašej rezervácie bol aktualizovaný na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Podrobnosti rezervácie:</strong><br>
                     <strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostí:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ak máte akékoľvek otázky, kontaktujte nás.</p>
                     <p>S pozdravom,<br>Tím reštaurácie</p>',
    ],
    'reservation_details' => 'Podrobnosti rezervácie',
    'date' => 'Dátum',
    'time' => 'Čas',
    'guests' => 'Hostia',
    'special_requests' => 'Špeciálne požiadavky',
    'visit_website' => 'Navštívte našu webovú stránku',
    'view_reservation' => 'Zobraziť rezerváciu',
    'cancel_or_modify' => 'Ak potrebujete zrušiť alebo upraviť svoju rezerváciu, kontaktujte nás prosím na :restaurant_phone alebo :restaurant_email.',
    'contact_us' => 'Ak máte akékoľvek otázky, kontaktujte nás prosím na :restaurant_phone alebo :restaurant_email.',
    'status' => 'Stav',
    'confirmed' => 'Potvrdené',
    'cancelled' => 'Zrušené',
    'note_from_restaurant' => 'Poznámka z reštaurácie',
    'make_new_reservation' => 'Vytvoriť novú rezerváciu',
    'status_update' => [
        'approved_title' => 'Rezervácia potvrdená!',
        'approved_message' => 'Skvelé správy! Vaša rezervácia bola potvrdená.',
        'cancelled_title' => 'Rezervácia zrušená',
        'cancelled_message' => 'Je nám ľúto, ale vaša rezervácia bola zrušená.',
        'default_title' => 'Aktualizácia stavu rezervácie',
        'default_message' => 'Stav vašej rezervácie bol aktualizovaný.',
    ],
];
