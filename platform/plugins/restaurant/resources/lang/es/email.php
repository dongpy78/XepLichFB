<?php

return [
    'new-reservation' => [
        'title' => 'Notificación de Nueva Reserva',
        'description' => 'Email enviado al administrador cuando se envía una nueva reserva',
        'subject' => 'Nueva reserva de {{ reservation_name }}',
        'header' => '¡Nueva Reserva Recibida!',
        'content' => '<p>Ha recibido una nueva reserva con los siguientes detalles:</p>
                     <p><strong>Nombre:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Teléfono:</strong> {{ reservation_phone }}<br>
                     <strong>Fecha:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Invitados:</strong> {{ number_of_guests }}<br>
                     <strong>Solicitudes Especiales:</strong> {{ special_requests }}</p>
                     <p>Por favor, inicie sesión en su panel de administración para gestionar esta reserva.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmación de Reserva',
        'description' => 'Email enviado al cliente después de enviar una reserva',
        'subject' => 'Su confirmación de reserva',
        'header' => 'Confirmación de Reserva',
        'content' => '<p>Estimado/a {{ reservation_name }},</p>
                     <p>¡Gracias por su reserva! Hemos recibido su solicitud de reserva con los siguientes detalles:</p>
                     <p><strong>Fecha:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Invitados:</strong> {{ number_of_guests }}</p>
                     <p>Nos pondremos en contacto con usted pronto para confirmar su reserva. Si tiene alguna pregunta, no dude en contactarnos.</p>
                     <p>Atentamente,<br>El Equipo del Restaurante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Actualización del Estado de la Reserva',
        'description' => 'Email enviado al cliente cuando se actualiza el estado de la reserva',
        'subject' => 'El estado de su reserva ha sido actualizado',
        'header' => 'Actualización del Estado de la Reserva',
        'content' => '<p>Estimado/a {{ reservation_name }},</p>
                     <p>El estado de su reserva ha sido actualizado a: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalles de la Reserva:</strong><br>
                     <strong>Fecha:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Invitados:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Si tiene alguna pregunta, por favor contáctenos.</p>
                     <p>Atentamente,<br>El Equipo del Restaurante</p>',
    ],
    'reservation_details' => 'Detalles de la Reserva',
    'date' => 'Fecha',
    'time' => 'Hora',
    'guests' => 'Invitados',
    'special_requests' => 'Solicitudes Especiales',
    'visit_website' => 'Visite Nuestro Sitio Web',
    'view_reservation' => 'Ver Reserva',
    'cancel_or_modify' => 'Si necesita cancelar o modificar su reserva, por favor contáctenos en :restaurant_phone o :restaurant_email.',
    'contact_us' => 'Si tiene alguna pregunta, por favor contáctenos en :restaurant_phone o :restaurant_email.',
    'status' => 'Estado',
    'confirmed' => 'Confirmado',
    'cancelled' => 'Cancelado',
    'note_from_restaurant' => 'Nota del Restaurante',
    'make_new_reservation' => 'Hacer Nueva Reserva',
    'status_update' => [
        'approved_title' => '¡Reserva Confirmada!',
        'approved_message' => '¡Buenas noticias! Su reserva ha sido confirmada.',
        'cancelled_title' => 'Reserva Cancelada',
        'cancelled_message' => 'Lo sentimos, pero su reserva ha sido cancelada.',
        'default_title' => 'Actualización del Estado de la Reserva',
        'default_message' => 'El estado de su reserva ha sido actualizado.',
    ],
];
