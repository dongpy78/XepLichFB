<?php

return [
    'new-reservation' => [
        'title' => 'Naujo rezervavimo pranešimas',
        'description' => 'El. laiškas, siunčiamas administratoriui, kai pateikiamas naujas rezervavimas',
        'subject' => 'Naujas rezervavimas iš {{ reservation_name }}',
        'header' => 'Gautas naujas rezervavimas!',
        'content' => '<p>Gavote naują rezervavimą su šia informacija:</p>
                     <p><strong>Vardas:</strong> {{ reservation_name }}<br>
                     <strong>El. paštas:</strong> {{ reservation_email }}<br>
                     <strong>Telefonas:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Laikas:</strong> {{ reservation_time }}<br>
                     <strong>Svečių skaičius:</strong> {{ number_of_guests }}<br>
                     <strong>Specialūs pageidavimai:</strong> {{ special_requests }}</p>
                     <p>Prisijunkite prie administravimo skydelio, kad valdytumėte šį rezervavimą.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Rezervavimo patvirtinimas',
        'description' => 'El. laiškas, siunčiamas klientui po rezervavimo pateikimo',
        'subject' => 'Jūsų rezervavimo patvirtinimas',
        'header' => 'Rezervavimo patvirtinimas',
        'content' => '<p>Gerb. {{ reservation_name }},</p>
                     <p>Dėkojame už rezervavimą! Gavome jūsų užsakymą su šia informacija:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Laikas:</strong> {{ reservation_time }}<br>
                     <strong>Svečių skaičius:</strong> {{ number_of_guests }}</p>
                     <p>Netrukus susisieksime su jumis, kad patvirtintume rezervavimą. Jei turite klausimų, nedvejodami susisiekite su mumis.</p>
                     <p>Pagarbiai,<br>Restorano komanda</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Rezervavimo būsenos atnaujinimas',
        'description' => 'El. laiškas, siunčiamas klientui, kai atnaujinama rezervavimo būsena',
        'subject' => 'Jūsų rezervavimo būsena buvo atnaujinta',
        'header' => 'Rezervavimo būsenos atnaujinimas',
        'content' => '<p>Gerb. {{ reservation_name }},</p>
                     <p>Jūsų rezervavimo būsena buvo atnaujinta į: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Rezervavimo informacija:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Laikas:</strong> {{ reservation_time }}<br>
                     <strong>Svečių skaičius:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Jei turite klausimų, susisiekite su mumis.</p>
                     <p>Pagarbiai,<br>Restorano komanda</p>',
    ],
    'reservation_details' => 'Rezervavimo informacija',
    'date' => 'Data',
    'time' => 'Laikas',
    'guests' => 'Svečiai',
    'special_requests' => 'Specialūs pageidavimai',
    'visit_website' => 'Apsilankykite mūsų svetainėje',
    'view_reservation' => 'Peržiūrėti rezervavimą',
    'cancel_or_modify' => 'Jei reikia atšaukti ar keisti rezervavimą, susisiekite su mumis telefonu :restaurant_phone arba el. paštu :restaurant_email.',
    'contact_us' => 'Jei turite klausimų, susisiekite su mumis telefonu :restaurant_phone arba el. paštu :restaurant_email.',
    'status' => 'Būsena',
    'confirmed' => 'Patvirtinta',
    'cancelled' => 'Atšaukta',
    'note_from_restaurant' => 'Pastaba iš restorano',
    'make_new_reservation' => 'Atlikti naują rezervavimą',
    'status_update' => [
        'approved_title' => 'Rezervavimas patvirtintas!',
        'approved_message' => 'Puikios naujienos! Jūsų rezervavimas patvirtintas.',
        'cancelled_title' => 'Rezervavimas atšauktas',
        'cancelled_message' => 'Apgailestaujame, bet jūsų rezervavimas buvo atšauktas.',
        'default_title' => 'Rezervavimo būsenos atnaujinimas',
        'default_message' => 'Jūsų rezervavimo būsena buvo atnaujinta.',
    ],
];
