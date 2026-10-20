<?php

return [
    'new-reservation' => [
        'title' => 'Notificação de Nova Reserva',
        'description' => 'E-mail enviado ao administrador quando uma nova reserva é submetida',
        'subject' => 'Nova reserva de {{ reservation_name }}',
        'header' => 'Nova Reserva Recebida!',
        'content' => '<p>Você recebeu uma nova reserva com os seguintes detalhes:</p>
                     <p><strong>Nome:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefone:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}<br>
                     <strong>Pedidos Especiais:</strong> {{ special_requests }}</p>
                     <p>Por favor, faça login no painel de administração para gerenciar esta reserva.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmação de Reserva',
        'description' => 'E-mail enviado ao cliente após submeter uma reserva',
        'subject' => 'Confirmação da sua reserva',
        'header' => 'Confirmação de Reserva',
        'content' => '<p>Prezado(a) {{ reservation_name }},</p>
                     <p>Obrigado pela sua reserva! Recebemos o seu pedido de reserva com os seguintes detalhes:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>Entraremos em contato em breve para confirmar sua reserva. Se tiver alguma dúvida, não hesite em nos contactar.</p>
                     <p>Atenciosamente,<br>A Equipe do Restaurante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Atualização do Status da Reserva',
        'description' => 'E-mail enviado ao cliente quando o status da reserva é atualizado',
        'subject' => 'O status da sua reserva foi atualizado',
        'header' => 'Atualização do Status da Reserva',
        'content' => '<p>Prezado(a) {{ reservation_name }},</p>
                     <p>O status da sua reserva foi atualizado para: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalhes da Reserva:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Se tiver alguma dúvida, por favor entre em contato conosco.</p>
                     <p>Atenciosamente,<br>A Equipe do Restaurante</p>',
    ],
    'reservation_details' => 'Detalhes da Reserva',
    'date' => 'Data',
    'time' => 'Horário',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'visit_website' => 'Visite Nosso Site',
    'view_reservation' => 'Ver Reserva',
    'cancel_or_modify' => 'Se precisar cancelar ou modificar sua reserva, entre em contato conosco através do :restaurant_phone ou :restaurant_email.',
    'contact_us' => 'Se tiver alguma dúvida, entre em contato conosco através do :restaurant_phone ou :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Confirmada',
    'cancelled' => 'Cancelada',
    'note_from_restaurant' => 'Nota do Restaurante',
    'make_new_reservation' => 'Fazer Nova Reserva',
    'status_update' => [
        'approved_title' => 'Reserva Confirmada!',
        'approved_message' => 'Ótimas notícias! Sua reserva foi confirmada.',
        'cancelled_title' => 'Reserva Cancelada',
        'cancelled_message' => 'Lamentamos, mas sua reserva foi cancelada.',
        'default_title' => 'Atualização do Status da Reserva',
        'default_message' => 'O status da sua reserva foi atualizado.',
    ],
];
