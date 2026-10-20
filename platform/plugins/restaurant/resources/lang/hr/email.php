<?php

return [
    'new-reservation' => [
        'title' => 'Obavijest o novoj rezervaciji',
        'description' => 'E-pošta poslana administratoru kada je poslana nova rezervacija',
        'subject' => 'Nova rezervacija od {{ reservation_name }}',
        'header' => 'Primljena nova rezervacija!',
        'content' => '<p>Primili ste novu rezervaciju sa sljedećim detaljima:</p>
                     <p><strong>Ime:</strong> {{ reservation_name }}<br>
                     <strong>E-pošta:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vrijeme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}<br>
                     <strong>Posebni zahtjevi:</strong> {{ special_requests }}</p>
                     <p>Prijavite se u svoju administratorsku ploču kako biste upravljali ovom rezervacijom.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potvrda rezervacije',
        'description' => 'E-pošta poslana kupcu nakon podnošenja rezervacije',
        'subject' => 'Potvrda vaše rezervacije',
        'header' => 'Potvrda rezervacije',
        'content' => '<p>Poštovani/a {{ reservation_name }},</p>
                     <p>Hvala vam na rezervaciji! Primili smo vaš zahtjev za rezervaciju sa sljedećim detaljima:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vrijeme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}</p>
                     <p>Uskoro ćemo vas kontaktirati kako bismo potvrdili vašu rezervaciju. Ako imate bilo kakvih pitanja, slobodno nas kontaktirajte.</p>
                     <p>Srdačan pozdrav,<br>Restoranski tim</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Ažuriranje statusa rezervacije',
        'description' => 'E-pošta poslana kupcu kada se ažurira status rezervacije',
        'subject' => 'Status vaše rezervacije je ažuriran',
        'header' => 'Ažuriranje statusa rezervacije',
        'content' => '<p>Poštovani/a {{ reservation_name }},</p>
                     <p>Status vaše rezervacije je ažuriran na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalji rezervacije:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Vrijeme:</strong> {{ reservation_time }}<br>
                     <strong>Broj gostiju:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ako imate bilo kakvih pitanja, kontaktirajte nas.</p>
                     <p>Srdačan pozdrav,<br>Restoranski tim</p>',
    ],
    'reservation_details' => 'Detalji rezervacije',
    'date' => 'Datum',
    'time' => 'Vrijeme',
    'guests' => 'Gosti',
    'special_requests' => 'Posebni zahtjevi',
    'visit_website' => 'Posjetite našu web stranicu',
    'view_reservation' => 'Pogledaj rezervaciju',
    'cancel_or_modify' => 'Ako trebate otkazati ili izmijeniti svoju rezervaciju, kontaktirajte nas na :restaurant_phone ili :restaurant_email.',
    'contact_us' => 'Ako imate bilo kakvih pitanja, kontaktirajte nas na :restaurant_phone ili :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Potvrđeno',
    'cancelled' => 'Otkazano',
    'note_from_restaurant' => 'Napomena od restorana',
    'make_new_reservation' => 'Napravite novu rezervaciju',
    'status_update' => [
        'approved_title' => 'Rezervacija potvrđena!',
        'approved_message' => 'Sjajne vijesti! Vaša rezervacija je potvrđena.',
        'cancelled_title' => 'Rezervacija otkazana',
        'cancelled_message' => 'Žao nam je, ali vaša rezervacija je otkazana.',
        'default_title' => 'Ažuriranje statusa rezervacije',
        'default_message' => 'Status vaše rezervacije je ažuriran.',
    ],
];
