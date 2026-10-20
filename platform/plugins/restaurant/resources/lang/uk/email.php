<?php

return [
    'new-reservation' => [
        'title' => 'Сповіщення про нове бронювання',
        'description' => 'Електронний лист, надісланий адміністратору, коли подано нове бронювання',
        'subject' => 'Нове бронювання від {{ reservation_name }}',
        'header' => 'Отримано нове бронювання!',
        'content' => '<p>Ви отримали нове бронювання з такими деталями:</p>
                     <p><strong>Ім\'я:</strong> {{ reservation_name }}<br>
                     <strong>Електронна пошта:</strong> {{ reservation_email }}<br>
                     <strong>Телефон:</strong> {{ reservation_phone }}<br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Кількість гостей:</strong> {{ number_of_guests }}<br>
                     <strong>Особливі побажання:</strong> {{ special_requests }}</p>
                     <p>Будь ласка, увійдіть до панелі адміністратора, щоб керувати цим бронюванням.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Підтвердження бронювання',
        'description' => 'Електронний лист, надісланий клієнту після подання бронювання',
        'subject' => 'Підтвердження вашого бронювання',
        'header' => 'Підтвердження бронювання',
        'content' => '<p>Шановний(-а) {{ reservation_name }},</p>
                     <p>Дякуємо за ваше бронювання! Ми отримали ваш запит на бронювання з такими деталями:</p>
                     <p><strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Кількість гостей:</strong> {{ number_of_guests }}</p>
                     <p>Ми зв\'яжемося з вами найближчим часом, щоб підтвердити ваше бронювання. Якщо у вас є запитання, не соромтеся зв\'язатися з нами.</p>
                     <p>З повагою,<br>Команда ресторану</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Оновлення статусу бронювання',
        'description' => 'Електронний лист, надісланий клієнту, коли статус бронювання оновлено',
        'subject' => 'Статус вашого бронювання оновлено',
        'header' => 'Оновлення статусу бронювання',
        'content' => '<p>Шановний(-а) {{ reservation_name }},</p>
                     <p>Статус вашого бронювання оновлено до: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Деталі бронювання:</strong><br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Час:</strong> {{ reservation_time }}<br>
                     <strong>Кількість гостей:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Якщо у вас є запитання, будь ласка, зв\'яжіться з нами.</p>
                     <p>З повагою,<br>Команда ресторану</p>',
    ],
    'reservation_details' => 'Деталі бронювання',
    'date' => 'Дата',
    'time' => 'Час',
    'guests' => 'Гості',
    'special_requests' => 'Особливі побажання',
    'visit_website' => 'Відвідайте наш веб-сайт',
    'view_reservation' => 'Переглянути бронювання',
    'cancel_or_modify' => 'Якщо вам потрібно скасувати або змінити своє бронювання, зв\'яжіться з нами за номером :restaurant_phone або :restaurant_email.',
    'contact_us' => 'Якщо у вас є запитання, зв\'яжіться з нами за номером :restaurant_phone або :restaurant_email.',
    'status' => 'Статус',
    'confirmed' => 'Підтверджено',
    'cancelled' => 'Скасовано',
    'note_from_restaurant' => 'Примітка від ресторану',
    'make_new_reservation' => 'Зробити нове бронювання',
    'status_update' => [
        'approved_title' => 'Бронювання підтверджено!',
        'approved_message' => 'Чудові новини! Ваше бронювання підтверджено.',
        'cancelled_title' => 'Бронювання скасовано',
        'cancelled_message' => 'Вибачте, але ваше бронювання було скасовано.',
        'default_title' => 'Оновлення статусу бронювання',
        'default_message' => 'Статус вашого бронювання оновлено.',
    ],
];
