<?php

return [
    'new-reservation' => [
        'title' => 'Obaveštenje o novoj rezervaciji',
        'description' => 'Email poslat administratoru kada je nova rezervacija podneta',
        'subject' => 'Nova rezervacija od {{ reservation_name }}',
        'header' => 'Primljena nova rezervacija!',
        'content' => '<p>Primili ste novu rezervaciju sa sledećim detaljima:</p>
                     <p><strong>Ime:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vreme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}<br>
                     <strong>Posebni zahtevi:</strong> {{ special_requests }}</p>
                     <p>Molimo prijavite se u administratorski panel da upravljate ovom rezervacijom.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potvrda rezervacije',
        'description' => 'Email poslat klijentu nakon podnošenja rezervacije',
        'subject' => 'Potvrda vaše rezervacije',
        'header' => 'Potvrda rezervacije',
        'content' => '<p>Poštovani {{ reservation_name }},</p>
                     <p>Hvala na vašoj rezervaciji! Primili smo vaš zahtev za rezervaciju sa sledećim detaljima:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vreme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}</p>
                     <p>Uskoro ćemo vas kontaktirati da potvrdimo vašu rezervaciju. Ako imate bilo kakva pitanja, slobodno nas kontaktirajte.</p>
                     <p>Srdačan pozdrav,<br>Tim restorana</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Ažuriranje statusa rezervacije',
        'description' => 'Email poslat klijentu kada je status rezervacije ažuriran',
        'subject' => 'Status vaše rezervacije je ažuriran',
        'header' => 'Ažuriranje statusa rezervacije',
        'content' => '<p>Poštovani {{ reservation_name }},</p>
                     <p>Status vaše rezervacije je ažuriran na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalji rezervacije:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vreme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ako imate bilo kakva pitanja, molimo kontaktirajte nas.</p>
                     <p>Srdačan pozdrav,<br>Tim restorana</p>',
    ],
    'reservation_details' => 'Detalji rezervacije',
    'date' => 'Datum',
    'time' => 'Vreme',
    'guests' => 'Gosti',
    'special_requests' => 'Posebni zahtevi',
    'visit_website' => 'Posetite našu veb stranicu',
    'view_reservation' => 'Pogledaj rezervaciju',
    'cancel_or_modify' => 'Ako treba da otkažete ili izmenite svoju rezervaciju, kontaktirajte nas na :restaurant_phone ili :restaurant_email.',
    'contact_us' => 'Ako imate bilo kakva pitanja, kontaktirajte nas na :restaurant_phone ili :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Potvrđeno',
    'cancelled' => 'Otkazano',
    'note_from_restaurant' => 'Napomena od restorana',
    'make_new_reservation' => 'Napravi novu rezervaciju',
    'status_update' => [
        'approved_title' => 'Rezervacija potvrđena!',
        'approved_message' => 'Sjajne vesti! Vaša rezervacija je potvrđena.',
        'cancelled_title' => 'Rezervacija otkazana',
        'cancelled_message' => 'Žao nam je, ali vaša rezervacija je otkazana.',
        'default_title' => 'Ažuriranje statusa rezervacije',
        'default_message' => 'Status vaše rezervacije je ažuriran.',
    ],
];
