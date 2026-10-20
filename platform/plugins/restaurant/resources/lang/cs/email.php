<?php

return [
    'new-reservation' => [
        'title' => 'Oznámení o nové rezervaci',
        'description' => 'E-mail odeslaný administrátorovi při odeslání nové rezervace',
        'subject' => 'Nová rezervace od {{ reservation_name }}',
        'header' => 'Nová rezervace přijata!',
        'content' => '<p>Obdrželi jste novou rezervaci s následujícími údaji:</p>
                     <p><strong>Jméno:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostů:</strong> {{ number_of_guests }}<br>
                     <strong>Zvláštní požadavky:</strong> {{ special_requests }}</p>
                     <p>Pro správu této rezervace se prosím přihlaste do administračního panelu.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potvrzení rezervace',
        'description' => 'E-mail odeslaný zákazníkovi po odeslání rezervace',
        'subject' => 'Potvrzení vaší rezervace',
        'header' => 'Potvrzení rezervace',
        'content' => '<p>Vážený/á {{ reservation_name }},</p>
                     <p>Děkujeme za vaši rezervaci! Obdrželi jsme váš požadavek na rezervaci s následujícími údaji:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostů:</strong> {{ number_of_guests }}</p>
                     <p>Brzy vás budeme kontaktovat pro potvrzení vaší rezervace. Pokud máte jakékoli otázky, neváhejte nás kontaktovat.</p>
                     <p>S pozdravem,<br>Tým restaurace</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Aktualizace stavu rezervace',
        'description' => 'E-mail odeslaný zákazníkovi při aktualizaci stavu rezervace',
        'subject' => 'Stav vaší rezervace byl aktualizován',
        'header' => 'Aktualizace stavu rezervace',
        'content' => '<p>Vážený/á {{ reservation_name }},</p>
                     <p>Stav vaší rezervace byl aktualizován na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Podrobnosti rezervace:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Počet hostů:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Pokud máte jakékoli otázky, kontaktujte nás.</p>
                     <p>S pozdravem,<br>Tým restaurace</p>',
    ],
    'reservation_details' => 'Podrobnosti rezervace',
    'date' => 'Datum',
    'time' => 'Čas',
    'guests' => 'Hosté',
    'special_requests' => 'Zvláštní požadavky',
    'visit_website' => 'Navštivte naše webové stránky',
    'view_reservation' => 'Zobrazit rezervaci',
    'cancel_or_modify' => 'Pokud potřebujete zrušit nebo upravit svou rezervaci, kontaktujte nás prosím na :restaurant_phone nebo :restaurant_email.',
    'contact_us' => 'Pokud máte jakékoli otázky, kontaktujte nás prosím na :restaurant_phone nebo :restaurant_email.',
    'status' => 'Stav',
    'confirmed' => 'Potvrzeno',
    'cancelled' => 'Zrušeno',
    'note_from_restaurant' => 'Poznámka z restaurace',
    'make_new_reservation' => 'Vytvořit novou rezervaci',
    'status_update' => [
        'approved_title' => 'Rezervace potvrzena!',
        'approved_message' => 'Skvělé zprávy! Vaše rezervace byla potvrzena.',
        'cancelled_title' => 'Rezervace zrušena',
        'cancelled_message' => 'Je nám líto, ale vaše rezervace byla zrušena.',
        'default_title' => 'Aktualizace stavu rezervace',
        'default_message' => 'Stav vaší rezervace byl aktualizován.',
    ],
];
