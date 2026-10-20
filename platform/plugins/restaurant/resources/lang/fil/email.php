<?php

return [
    'new-reservation' => [
        'title' => 'Abiso ng Bagong Reserbasyon',
        'description' => 'Email na ipinapadala sa admin kapag may bagong reserbasyon na isinumite',
        'subject' => 'Bagong reserbasyon mula kay {{ reservation_name }}',
        'header' => 'Nakatanggap ng Bagong Reserbasyon!',
        'content' => '<p>Nakatanggap ka ng bagong reserbasyon na may sumusunod na detalye:</p>
                     <p><strong>Pangalan:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Telepono:</strong> {{ reservation_phone }}<br>
                     <strong>Petsa:</strong> {{ reservation_date }}<br>
                     <strong>Oras:</strong> {{ reservation_time }}<br>
                     <strong>Bilang ng mga Bisita:</strong> {{ number_of_guests }}<br>
                     <strong>Mga Espesyal na Kahilingan:</strong> {{ special_requests }}</p>
                     <p>Mangyaring mag-log in sa iyong admin panel upang pamahalaan ang reserbasyon na ito.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Kumpirmasyon ng Reserbasyon',
        'description' => 'Email na ipinapadala sa customer pagkatapos magsumite ng reserbasyon',
        'subject' => 'Kumpirmasyon ng iyong reserbasyon',
        'header' => 'Kumpirmasyon ng Reserbasyon',
        'content' => '<p>Mahal na {{ reservation_name }},</p>
                     <p>Salamat sa iyong reserbasyon! Natanggap namin ang iyong kahilingan sa booking na may sumusunod na detalye:</p>
                     <p><strong>Petsa:</strong> {{ reservation_date }}<br>
                     <strong>Oras:</strong> {{ reservation_time }}<br>
                     <strong>Bilang ng mga Bisita:</strong> {{ number_of_guests }}</p>
                     <p>Makikipag-ugnayan kami sa iyo sa lalong madaling panahon upang kumpirmahin ang iyong reserbasyon. Kung mayroon kang anumang mga tanong, mangyaring huwag mag-atubiling makipag-ugnayan sa amin.</p>
                     <p>Pinakamahusay na pagbati,<br>Ang Restaurant Team</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Update ng Katayuan ng Reserbasyon',
        'description' => 'Email na ipinapadala sa customer kapag na-update ang katayuan ng reserbasyon',
        'subject' => 'Na-update ang katayuan ng iyong reserbasyon',
        'header' => 'Update ng Katayuan ng Reserbasyon',
        'content' => '<p>Mahal na {{ reservation_name }},</p>
                     <p>Na-update ang katayuan ng iyong reserbasyon sa: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Mga Detalye ng Reserbasyon:</strong><br>
                     <strong>Petsa:</strong> {{ reservation_date }}<br>
                     <strong>Oras:</strong> {{ reservation_time }}<br>
                     <strong>Bilang ng mga Bisita:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Kung mayroon kang anumang mga tanong, mangyaring makipag-ugnayan sa amin.</p>
                     <p>Pinakamahusay na pagbati,<br>Ang Restaurant Team</p>',
    ],
    'reservation_details' => 'Mga Detalye ng Reserbasyon',
    'date' => 'Petsa',
    'time' => 'Oras',
    'guests' => 'Mga Bisita',
    'special_requests' => 'Mga Espesyal na Kahilingan',
    'visit_website' => 'Bisitahin ang Aming Website',
    'view_reservation' => 'Tingnan ang Reserbasyon',
    'cancel_or_modify' => 'Kung kailangan mong kanselahin o baguhin ang iyong reserbasyon, mangyaring makipag-ugnayan sa amin sa :restaurant_phone o :restaurant_email.',
    'contact_us' => 'Kung mayroon kang anumang mga tanong, mangyaring makipag-ugnayan sa amin sa :restaurant_phone o :restaurant_email.',
    'status' => 'Katayuan',
    'confirmed' => 'Nakumpirma',
    'cancelled' => 'Nakansela',
    'note_from_restaurant' => 'Tala mula sa Restaurant',
    'make_new_reservation' => 'Gumawa ng Bagong Reserbasyon',
    'status_update' => [
        'approved_title' => 'Nakumpirma ang Reserbasyon!',
        'approved_message' => 'Magandang balita! Nakumpirma na ang iyong reserbasyon.',
        'cancelled_title' => 'Nakansela ang Reserbasyon',
        'cancelled_message' => 'Paumanhin, pero nakansela ang iyong reserbasyon.',
        'default_title' => 'Update ng Katayuan ng Reserbasyon',
        'default_message' => 'Na-update ang katayuan ng iyong reserbasyon.',
    ],
];
