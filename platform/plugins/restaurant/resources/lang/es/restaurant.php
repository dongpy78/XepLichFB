<?php

return [
    'menu_name' => 'Restaurante',
    'name' => 'Restaurante',
    'booking_calendar' => 'Calendario de Reservas',
    'reservation_detail' => 'Detalles de la Reserva',
    'form' => [
        'name' => 'Nombre',
        'email' => 'Email',
        'phone' => 'Teléfono',
        'reservation_date' => 'Fecha de Reserva',
        'reservation_time' => 'Hora de Reserva',
        'guests' => 'Invitados',
        'status' => 'Estado',
        'special_requests' => 'Solicitudes Especiales',
    ],
    'settings' => [
        'title' => 'Configuración',
        'description' => 'Configurar ajustes y preferencias del restaurante',
        'menu_title' => 'Configuración',
        'reservation_time_format' => 'Formato de hora de reserva',
        '12_hours_format' => '12 horas (AM/PM)',
        '24_hours_format' => '24 horas',
        'reservation_time_format_helper' => 'Elija el formato de hora para la selección de hora de reserva',
        'email' => [
            'templates' => [
                'title' => 'Plantillas de Email del Restaurante',
                'description' => 'Configurar plantillas de email para reservas y notificaciones del restaurante',
            ],
        ],
    ],

    'not_available' => 'No disponible',
    'delete_confirm_title' => 'Confirmar eliminación',
    'delete_confirm_message' => '¿Está seguro de que desea eliminar esta reserva? Esta acción no se puede deshacer.',
    'delete' => 'Eliminar',
];
