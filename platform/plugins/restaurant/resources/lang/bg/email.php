<?php

return [
    'new-reservation' => [
        'title' => 'Известие за нова резервация',
        'description' => 'Имейл, изпратен до администратора при подаване на нова резервация',
        'subject' => 'Нова резервация от {{ reservation_name }}',
        'header' => 'Получена е нова резервация!',
        'content' => '<p>Получихте нова резервация със следните подробности:</p>
                     <p><strong>Име:</strong> {{ reservation_name }}<br>
                     <strong>Имейл:</strong> {{ reservation_email }}<br>
                     <strong>Телефон:</strong> {{ reservation_phone }}<br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Брой гости:</strong> {{ number_of_guests }}<br>
                     <strong>Специални заявки:</strong> {{ special_requests }}</p>
                     <p>Моля, влезте в административния си панел, за да управлявате тази резервация.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Потвърждение на резервация',
        'description' => 'Имейл, изпратен до клиента след подаване на резервация',
        'subject' => 'Вашето потвърждение за резервация',
        'header' => 'Потвърждение на резервация',
        'content' => '<p>Уважаеми/а {{ reservation_name }},</p>
                     <p>Благодарим ви за резервацията! Получихме вашата заявка за резервация със следните подробности:</p>
                     <p><strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Брой гости:</strong> {{ number_of_guests }}</p>
                     <p>Скоро ще се свържем с вас, за да потвърдим резервацията ви. Ако имате някакви въпроси, моля, не се колебайте да се свържете с нас.</p>
                     <p>С най-добри пожелания,<br>Екипът на ресторанта</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Актуализация на статуса на резервацията',
        'description' => 'Имейл, изпратен до клиента при актуализиране на статуса на резервацията',
        'subject' => 'Статусът на вашата резервация е актуализиран',
        'header' => 'Актуализация на статуса на резервацията',
        'content' => '<p>Уважаеми/а {{ reservation_name }},</p>
                     <p>Статусът на вашата резервация е актуализиран на: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Подробности за резервацията:</strong><br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Брой гости:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Ако имате някакви въпроси, моля, свържете се с нас.</p>
                     <p>С най-добри пожелания,<br>Екипът на ресторанта</p>',
    ],
    'reservation_details' => 'Подробности за резервацията',
    'date' => 'Дата',
    'time' => 'Час',
    'guests' => 'Гости',
    'special_requests' => 'Специални заявки',
    'visit_website' => 'Посетете нашия уебсайт',
    'view_reservation' => 'Преглед на резервацията',
    'cancel_or_modify' => 'Ако трябва да отмените или промените резервацията си, моля, свържете се с нас на :restaurant_phone или :restaurant_email.',
    'contact_us' => 'Ако имате въпроси, моля, свържете се с нас на :restaurant_phone или :restaurant_email.',
    'status' => 'Статус',
    'confirmed' => 'Потвърдена',
    'cancelled' => 'Отменена',
    'note_from_restaurant' => 'Бележка от ресторанта',
    'make_new_reservation' => 'Направете нова резервация',
    'status_update' => [
        'approved_title' => 'Резервацията е потвърдена!',
        'approved_message' => 'Чудесни новини! Вашата резервация е потвърдена.',
        'cancelled_title' => 'Резервацията е отменена',
        'cancelled_message' => 'Съжаляваме, но вашата резервация е отменена.',
        'default_title' => 'Актуализация на статуса на резервацията',
        'default_message' => 'Статусът на вашата резервация е актуализиран.',
    ],
];
