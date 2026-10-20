<?php

return [
    'new-reservation' => [
        'title' => 'Ny bokningsavisering',
        'description' => 'E-post som skickas till admin när en ny bokning skickas in',
        'subject' => 'Ny bokning från {{ reservation_name }}',
        'header' => 'Ny bokning mottagen!',
        'content' => '<p>Du har mottagit en ny bokning med följande detaljer:</p>
                     <p><strong>Namn:</strong> {{ reservation_name }}<br>
                     <strong>E-post:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gäster:</strong> {{ number_of_guests }}<br>
                     <strong>Särskilda önskemål:</strong> {{ special_requests }}</p>
                     <p>Vänligen logga in på din adminpanel för att hantera denna bokning.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Bokningsbekräftelse',
        'description' => 'E-post som skickas till kund efter att ha skickat in en bokning',
        'subject' => 'Din bokningsbekräftelse',
        'header' => 'Bokningsbekräftelse',
        'content' => '<p>Bästa {{ reservation_name }},</p>
                     <p>Tack för din bokning! Vi har mottagit din bokningsförfrågan med följande detaljer:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gäster:</strong> {{ number_of_guests }}</p>
                     <p>Vi kommer att kontakta dig snart för att bekräfta din bokning. Om du har några frågor, tveka inte att kontakta oss.</p>
                     <p>Med vänliga hälsningar,<br>Restaurangteamet</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Uppdatering av bokningsstatus',
        'description' => 'E-post som skickas till kund när bokningsstatusen uppdateras',
        'subject' => 'Din bokningsstatus har uppdaterats',
        'header' => 'Uppdatering av bokningsstatus',
        'content' => '<p>Bästa {{ reservation_name }},</p>
                     <p>Din bokningsstatus har uppdaterats till: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Bokningsdetaljer:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antal gäster:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Om du har några frågor, vänligen kontakta oss.</p>
                     <p>Med vänliga hälsningar,<br>Restaurangteamet</p>',
    ],
    'reservation_details' => 'Bokningsdetaljer',
    'date' => 'Datum',
    'time' => 'Tid',
    'guests' => 'Gäster',
    'special_requests' => 'Särskilda önskemål',
    'visit_website' => 'Besök vår webbplats',
    'view_reservation' => 'Visa bokning',
    'cancel_or_modify' => 'Om du behöver avboka eller ändra din bokning, vänligen kontakta oss på :restaurant_phone eller :restaurant_email.',
    'contact_us' => 'Om du har några frågor, vänligen kontakta oss på :restaurant_phone eller :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Bekräftad',
    'cancelled' => 'Avbokad',
    'note_from_restaurant' => 'Meddelande från restaurangen',
    'make_new_reservation' => 'Gör ny bokning',
    'status_update' => [
        'approved_title' => 'Bokning bekräftad!',
        'approved_message' => 'Goda nyheter! Din bokning har bekräftats.',
        'cancelled_title' => 'Bokning avbokad',
        'cancelled_message' => 'Vi beklagar, men din bokning har avbokats.',
        'default_title' => 'Uppdatering av bokningsstatus',
        'default_message' => 'Din bokningsstatus har uppdaterats.',
    ],
];
