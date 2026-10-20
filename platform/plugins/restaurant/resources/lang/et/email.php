<?php

return [
    'new-reservation' => [
        'title' => 'Uue broneeringu teatis',
        'description' => 'E-kiri, mis saadetakse administraatorile, kui uus broneering on esitatud',
        'subject' => 'Uus broneering kasutajalt {{ reservation_name }}',
        'header' => 'Uus broneering vastu võetud!',
        'content' => '<p>Olete saanud uue broneeringu järgmiste andmetega:</p>
                     <p><strong>Nimi:</strong> {{ reservation_name }}<br>
                     <strong>E-post:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Kuupäev:</strong> {{ reservation_date }}<br>
                     <strong>Kellaaeg:</strong> {{ reservation_time }}<br>
                     <strong>Külaliste arv:</strong> {{ number_of_guests }}<br>
                     <strong>Eritaotlused:</strong> {{ special_requests }}</p>
                     <p>Palun logige sisse oma halduspaneeli, et seda broneeringut hallata.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Broneeringu kinnitus',
        'description' => 'E-kiri, mis saadetakse kliendile pärast broneeringu esitamist',
        'subject' => 'Teie broneeringu kinnitus',
        'header' => 'Broneeringu kinnitus',
        'content' => '<p>Lugupeetud {{ reservation_name }},</p>
                     <p>Täname teid broneeringu eest! Oleme saanud teie broneeringu taotluse järgmiste andmetega:</p>
                     <p><strong>Kuupäev:</strong> {{ reservation_date }}<br>
                     <strong>Kellaaeg:</strong> {{ reservation_time }}<br>
                     <strong>Külaliste arv:</strong> {{ number_of_guests }}</p>
                     <p>Võtame teiega peagi ühendust, et teie broneering kinnitada. Kui teil on küsimusi, ärge kartke meiega ühendust võtta.</p>
                     <p>Lugupidamisega,<br>Restorani meeskond</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Broneeringu oleku uuendus',
        'description' => 'E-kiri, mis saadetakse kliendile, kui broneeringu olek on uuendatud',
        'subject' => 'Teie broneeringu olek on uuendatud',
        'header' => 'Broneeringu oleku uuendus',
        'content' => '<p>Lugupeetud {{ reservation_name }},</p>
                     <p>Teie broneeringu olek on uuendatud: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Broneeringu üksikasjad:</strong><br>
                     <strong>Kuupäev:</strong> {{ reservation_date }}<br>
                     <strong>Kellaaeg:</strong> {{ reservation_time }}<br>
                     <strong>Külaliste arv:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Kui teil on küsimusi, võtke meiega ühendust.</p>
                     <p>Lugupidamisega,<br>Restorani meeskond</p>',
    ],
    'reservation_details' => 'Broneeringu üksikasjad',
    'date' => 'Kuupäev',
    'time' => 'Kellaaeg',
    'guests' => 'Külalised',
    'special_requests' => 'Eritaotlused',
    'visit_website' => 'Külasta meie veebilehte',
    'view_reservation' => 'Vaata broneeringut',
    'cancel_or_modify' => 'Kui peate broneeringu tühistama või muutma, võtke meiega ühendust telefonil :restaurant_phone või e-posti aadressil :restaurant_email.',
    'contact_us' => 'Kui teil on küsimusi, võtke meiega ühendust telefonil :restaurant_phone või e-posti aadressil :restaurant_email.',
    'status' => 'Olek',
    'confirmed' => 'Kinnitatud',
    'cancelled' => 'Tühistatud',
    'note_from_restaurant' => 'Märkus restoranilt',
    'make_new_reservation' => 'Tee uus broneering',
    'status_update' => [
        'approved_title' => 'Broneering kinnitatud!',
        'approved_message' => 'Suurepärased uudised! Teie broneering on kinnitatud.',
        'cancelled_title' => 'Broneering tühistatud',
        'cancelled_message' => 'Kahjuks on teie broneering tühistatud.',
        'default_title' => 'Broneeringu oleku uuendus',
        'default_message' => 'Teie broneeringu olek on uuendatud.',
    ],
];
