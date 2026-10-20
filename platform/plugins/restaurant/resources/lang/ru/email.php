<?php

return [
    'new-reservation' => [
        'title' => 'Уведомление о новой бронировании',
        'description' => 'Email отправленный администратору при получении нового бронирования',
        'subject' => 'Новое бронирование от {{ reservation_name }}',
        'header' => 'Получено новое бронирование!',
        'content' => '<p>Вы получили новое бронирование со следующими данными:</p>
                     <p><strong>Имя:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Телефон:</strong> {{ reservation_phone }}<br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Время:</strong> {{ reservation_time }}<br>
                     <strong>Количество гостей:</strong> {{ number_of_guests }}<br>
                     <strong>Особые пожелания:</strong> {{ special_requests }}</p>
                     <p>Пожалуйста, войдите в панель администратора, чтобы управлять этим бронированием.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Подтверждение бронирования',
        'description' => 'Email отправленный клиенту после оформления бронирования',
        'subject' => 'Подтверждение вашего бронирования',
        'header' => 'Подтверждение бронирования',
        'content' => '<p>Уважаемый(-ая) {{ reservation_name }},</p>
                     <p>Спасибо за ваше бронирование! Мы получили ваш запрос на бронирование со следующими деталями:</p>
                     <p><strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Время:</strong> {{ reservation_time }}<br>
                     <strong>Количество гостей:</strong> {{ number_of_guests }}</p>
                     <p>Мы скоро свяжемся с вами для подтверждения бронирования. Если у вас есть вопросы, не стесняйтесь обращаться к нам.</p>
                     <p>С уважением,<br>Команда ресторана</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Обновление статуса бронирования',
        'description' => 'Email отправленный клиенту при обновлении статуса бронирования',
        'subject' => 'Статус вашего бронирования обновлен',
        'header' => 'Обновление статуса бронирования',
        'content' => '<p>Уважаемый(-ая) {{ reservation_name }},</p>
                     <p>Статус вашего бронирования обновлен на: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Детали бронирования:</strong><br>
                     <strong>Дата:</strong> {{ reservation_date }}<br>
                     <strong>Время:</strong> {{ reservation_time }}<br>
                     <strong>Количество гостей:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Если у вас есть вопросы, пожалуйста, свяжитесь с нами.</p>
                     <p>С уважением,<br>Команда ресторана</p>',
    ],
    'reservation_details' => 'Детали бронирования',
    'date' => 'Дата',
    'time' => 'Время',
    'guests' => 'Гости',
    'special_requests' => 'Особые пожелания',
    'visit_website' => 'Посетить наш сайт',
    'view_reservation' => 'Просмотр бронирования',
    'cancel_or_modify' => 'Если вам нужно отменить или изменить бронирование, пожалуйста, свяжитесь с нами по телефону :restaurant_phone или email :restaurant_email.',
    'contact_us' => 'Если у вас есть вопросы, пожалуйста, свяжитесь с нами по телефону :restaurant_phone или email :restaurant_email.',
    'status' => 'Статус',
    'confirmed' => 'Подтверждено',
    'cancelled' => 'Отменено',
    'note_from_restaurant' => 'Заметка от ресторана',
    'make_new_reservation' => 'Сделать новое бронирование',
    'status_update' => [
        'approved_title' => 'Бронирование подтверждено!',
        'approved_message' => 'Отличные новости! Ваше бронирование подтверждено.',
        'cancelled_title' => 'Бронирование отменено',
        'cancelled_message' => 'Сожалеем, но ваше бронирование было отменено.',
        'default_title' => 'Обновление статуса бронирования',
        'default_message' => 'Статус вашего бронирования обновлен.',
    ],
];
