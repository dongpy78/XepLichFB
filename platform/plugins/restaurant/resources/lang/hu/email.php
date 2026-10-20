<?php

return [
    'new-reservation' => [
        'title' => 'Új foglalás értesítés',
        'description' => 'E-mail az adminisztrátornak új foglalás beküldésekor',
        'subject' => 'Új foglalás {{ reservation_name }} vendégtől',
        'header' => 'Új foglalás érkezett!',
        'content' => '<p>Új foglalást kapott a következő adatokkal:</p>
                     <p><strong>Név:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Időpont:</strong> {{ reservation_time }}<br>
                     <strong>Vendégek száma:</strong> {{ number_of_guests }}<br>
                     <strong>Különleges kérések:</strong> {{ special_requests }}</p>
                     <p>Kérjük, jelentkezzen be az adminisztrátori panelbe a foglalás kezeléséhez.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Foglalás visszaigazolása',
        'description' => 'E-mail az ügyfélnek a foglalás beküldése után',
        'subject' => 'Foglalás visszaigazolása',
        'header' => 'Foglalás visszaigazolása',
        'content' => '<p>Kedves {{ reservation_name }}!</p>
                     <p>Köszönjük a foglalását! Megkaptuk foglalási kérelmét a következő adatokkal:</p>
                     <p><strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Időpont:</strong> {{ reservation_time }}<br>
                     <strong>Vendégek száma:</strong> {{ number_of_guests }}</p>
                     <p>Hamarosan felvesszük Önnel a kapcsolatot a foglalás megerősítéséhez. Ha bármilyen kérdése van, ne habozzon kapcsolatba lépni velünk.</p>
                     <p>Üdvözlettel,<br>Az étterem csapata</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Foglalás állapot frissítése',
        'description' => 'E-mail az ügyfélnek, amikor a foglalás állapota frissül',
        'subject' => 'Foglalásának állapota frissült',
        'header' => 'Foglalás állapot frissítése',
        'content' => '<p>Kedves {{ reservation_name }}!</p>
                     <p>Foglalásának állapota frissült: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Foglalás részletei:</strong><br>
                     <strong>Dátum:</strong> {{ reservation_date }}<br>
                     <strong>Időpont:</strong> {{ reservation_time }}<br>
                     <strong>Vendégek száma:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ha bármilyen kérdése van, kérjük, vegye fel velünk a kapcsolatot.</p>
                     <p>Üdvözlettel,<br>Az étterem csapata</p>',
    ],
    'reservation_details' => 'Foglalás részletei',
    'date' => 'Dátum',
    'time' => 'Időpont',
    'guests' => 'Vendégek',
    'special_requests' => 'Különleges kérések',
    'visit_website' => 'Látogassa meg weboldalunkat',
    'view_reservation' => 'Foglalás megtekintése',
    'cancel_or_modify' => 'Ha le szeretné mondani vagy módosítani szeretné foglalását, kérjük, vegye fel velünk a kapcsolatot a :restaurant_phone telefonszámon vagy a :restaurant_email e-mail címen.',
    'contact_us' => 'Ha bármilyen kérdése van, vegye fel velünk a kapcsolatot a :restaurant_phone telefonszámon vagy a :restaurant_email e-mail címen.',
    'status' => 'Állapot',
    'confirmed' => 'Megerősítve',
    'cancelled' => 'Lemondva',
    'note_from_restaurant' => 'Megjegyzés az étteremtől',
    'make_new_reservation' => 'Új foglalás létrehozása',
    'status_update' => [
        'approved_title' => 'Foglalás megerősítve!',
        'approved_message' => 'Nagyszerű hír! Foglalása megerősítésre került.',
        'cancelled_title' => 'Foglalás lemondva',
        'cancelled_message' => 'Sajnáljuk, de foglalását lemondták.',
        'default_title' => 'Foglalás állapot frissítése',
        'default_message' => 'Foglalásának állapota frissült.',
    ],
];
