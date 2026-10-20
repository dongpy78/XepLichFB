<?php

return [
    'new-reservation' => [
        'title' => 'Jaunas rezervācijas paziņojums',
        'description' => 'E-pasts, kas nosūtīts administratoram, kad iesniegta jauna rezervācija',
        'subject' => 'Jauna rezervācija no {{ reservation_name }}',
        'header' => 'Saņemta jauna rezervācija!',
        'content' => '<p>Jūs esat saņēmis jaunu rezervāciju ar šādiem datiem:</p>
                     <p><strong>Vārds:</strong> {{ reservation_name }}<br>
                     <strong>E-pasts:</strong> {{ reservation_email }}<br>
                     <strong>Tālrunis:</strong> {{ reservation_phone }}<br>
                     <strong>Datums:</strong> {{ reservation_date }}<br>
                     <strong>Laiks:</strong> {{ reservation_time }}<br>
                     <strong>Viesu skaits:</strong> {{ number_of_guests }}<br>
                     <strong>Īpaši lūgumi:</strong> {{ special_requests }}</p>
                     <p>Lūdzu, piesakieties savā administrācijas panelī, lai pārvaldītu šo rezervāciju.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Rezervācijas apstiprinājums',
        'description' => 'E-pasts, kas nosūtīts klientam pēc rezervācijas iesniegšanas',
        'subject' => 'Jūsu rezervācijas apstiprinājums',
        'header' => 'Rezervācijas apstiprinājums',
        'content' => '<p>Cien. {{ reservation_name }},</p>
                     <p>Paldies par rezervāciju! Mēs esam saņēmuši jūsu rezervācijas pieprasījumu ar šādiem datiem:</p>
                     <p><strong>Datums:</strong> {{ reservation_date }}<br>
                     <strong>Laiks:</strong> {{ reservation_time }}<br>
                     <strong>Viesu skaits:</strong> {{ number_of_guests }}</p>
                     <p>Mēs drīzumā ar jums sazināsimies, lai apstiprinātu rezervāciju. Ja jums ir kādi jautājumi, lūdzu, nevilcinieties ar mums sazināties.</p>
                     <p>Ar cieņu,<br>Restorāna komanda</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Rezervācijas statusa atjauninājums',
        'description' => 'E-pasts, kas nosūtīts klientam, kad tiek atjaunināts rezervācijas statuss',
        'subject' => 'Jūsu rezervācijas statuss ir atjaunināts',
        'header' => 'Rezervācijas statusa atjauninājums',
        'content' => '<p>Cien. {{ reservation_name }},</p>
                     <p>Jūsu rezervācijas statuss ir atjaunināts uz: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Rezervācijas informācija:</strong><br>
                     <strong>Datums:</strong> {{ reservation_date }}<br>
                     <strong>Laiks:</strong> {{ reservation_time }}<br>
                     <strong>Viesu skaits:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ja jums ir kādi jautājumi, lūdzu, sazinieties ar mums.</p>
                     <p>Ar cieņu,<br>Restorāna komanda</p>',
    ],
    'reservation_details' => 'Rezervācijas informācija',
    'date' => 'Datums',
    'time' => 'Laiks',
    'guests' => 'Viesi',
    'special_requests' => 'Īpaši lūgumi',
    'visit_website' => 'Apmeklējiet mūsu vietni',
    'view_reservation' => 'Skatīt rezervāciju',
    'cancel_or_modify' => 'Ja jums ir nepieciešams atcelt vai modificēt rezervāciju, lūdzu, sazinieties ar mums pa tālruni :restaurant_phone vai e-pastu :restaurant_email.',
    'contact_us' => 'Ja jums ir kādi jautājumi, lūdzu, sazinieties ar mums pa tālruni :restaurant_phone vai e-pastu :restaurant_email.',
    'status' => 'Statuss',
    'confirmed' => 'Apstiprināts',
    'cancelled' => 'Atcelts',
    'note_from_restaurant' => 'Piezīme no restorāna',
    'make_new_reservation' => 'Veikt jaunu rezervāciju',
    'status_update' => [
        'approved_title' => 'Rezervācija apstiprināta!',
        'approved_message' => 'Lieliskas ziņas! Jūsu rezervācija ir apstiprināta.',
        'cancelled_title' => 'Rezervācija atcelta',
        'cancelled_message' => 'Mums žēl, bet jūsu rezervācija ir atcelta.',
        'default_title' => 'Rezervācijas statusa atjauninājums',
        'default_message' => 'Jūsu rezervācijas statuss ir atjaunināts.',
    ],
];
