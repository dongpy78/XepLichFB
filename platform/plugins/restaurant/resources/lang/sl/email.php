<?php

return [
    'new-reservation' => [
        'title' => 'Obvestilo o novi rezervaciji',
        'description' => 'E-pošta poslana skrbniku, ko je oddana nova rezervacija',
        'subject' => 'Nova rezervacija od {{ reservation_name }}',
        'header' => 'Prejeta nova rezervacija!',
        'content' => '<p>Prejeli ste novo rezervacijo s sledečimi podrobnostmi:</p>
                     <p><strong>Ime:</strong> {{ reservation_name }}<br>
                     <strong>E-pošta:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}<br>
                     <strong>Posebne zahteve:</strong> {{ special_requests }}</p>
                     <p>Prosimo, prijavite se v upravljalni panel za upravljanje te rezervacije.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potrditev rezervacije',
        'description' => 'E-pošta poslana stranki po oddaji rezervacije',
        'subject' => 'Potrditev vaše rezervacije',
        'header' => 'Potrditev rezervacije',
        'content' => '<p>Spoštovani {{ reservation_name }},</p>
                     <p>Hvala za vašo rezervacijo! Prejeli smo vašo prošnjo za rezervacijo s sledečimi podrobnostmi:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}</p>
                     <p>Kmalu vas bomo kontaktirali za potrditev vaše rezervacije. Če imate kakršna koli vprašanja, nas brez zadržkov kontaktirajte.</p>
                     <p>Lep pozdrav,<br>Ekipa restavracije</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Posodobitev statusa rezervacije',
        'description' => 'E-pošta poslana stranki, ko je status rezervacije posodobljen',
        'subject' => 'Status vaše rezervacije je bil posodobljen',
        'header' => 'Posodobitev statusa rezervacije',
        'content' => '<p>Spoštovani {{ reservation_name }},</p>
                     <p>Status vaše rezervacije je bil posodobljen na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Podrobnosti rezervacije:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Če imate kakršna koli vprašanja, nas prosimo kontaktirajte.</p>
                     <p>Lep pozdrav,<br>Ekipa restavracije</p>',
    ],
    'reservation_details' => 'Podrobnosti rezervacije',
    'date' => 'Datum',
    'time' => 'Čas',
    'guests' => 'Gostje',
    'special_requests' => 'Posebne zahteve',
    'visit_website' => 'Obiščite našo spletno stran',
    'view_reservation' => 'Poglej rezervacijo',
    'cancel_or_modify' => 'Če morate preklicati ali spremeniti svojo rezervacijo, nas kontaktirajte na :restaurant_phone ali :restaurant_email.',
    'contact_us' => 'Če imate kakršna koli vprašanja, nas kontaktirajte na :restaurant_phone ali :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Potrjena',
    'cancelled' => 'Preklicana',
    'note_from_restaurant' => 'Opomba restavracije',
    'make_new_reservation' => 'Naredi novo rezervacijo',
    'status_update' => [
        'approved_title' => 'Rezervacija potrjena!',
        'approved_message' => 'Odlične novice! Vaša rezervacija je bila potrjena.',
        'cancelled_title' => 'Rezervacija preklicana',
        'cancelled_message' => 'Opravičujemo se, vendar je bila vaša rezervacija preklicana.',
        'default_title' => 'Posodobitev statusa rezervacije',
        'default_message' => 'Status vaše rezervacije je bil posodobljen.',
    ],
];
