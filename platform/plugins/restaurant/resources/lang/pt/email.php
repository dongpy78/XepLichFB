<?php

return [
    'new-reservation' => [
        'title' => 'Notificação de Nova Reserva',
        'description' => 'E-mail enviado ao administrador quando uma nova reserva é submetida',
        'subject' => 'Nova reserva de {{ reservation_name }}',
        'header' => 'Nova Reserva Recebida!',
        'content' => '<p>Recebeu uma nova reserva com os seguintes detalhes:</p>
                     <p><strong>Nome:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefone:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}<br>
                     <strong>Pedidos Especiais:</strong> {{ special_requests }}</p>
                     <p>Por favor, inicie sessão no painel de administração para gerir esta reserva.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmação de Reserva',
        'description' => 'E-mail enviado ao cliente após submeter uma reserva',
        'subject' => 'Confirmação da sua reserva',
        'header' => 'Confirmação de Reserva',
        'content' => '<p>Caro(a) {{ reservation_name }},</p>
                     <p>Obrigado pela sua reserva! Recebemos o seu pedido de reserva com os seguintes detalhes:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>Entraremos em contacto em breve para confirmar a sua reserva. Se tiver alguma questão, não hesite em contactar-nos.</p>
                     <p>Cumprimentos,<br>A Equipa do Restaurante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Atualização do Estado da Reserva',
        'description' => 'E-mail enviado ao cliente quando o estado da reserva é atualizado',
        'subject' => 'O estado da sua reserva foi atualizado',
        'header' => 'Atualização do Estado da Reserva',
        'content' => '<p>Caro(a) {{ reservation_name }},</p>
                     <p>O estado da sua reserva foi atualizado para: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalhes da Reserva:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Se tiver alguma questão, por favor contacte-nos.</p>
                     <p>Cumprimentos,<br>A Equipa do Restaurante</p>',
    ],
    'reservation_details' => 'Detalhes da Reserva',
    'date' => 'Data',
    'time' => 'Hora',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'visit_website' => 'Visite o Nosso Website',
    'view_reservation' => 'Ver Reserva',
    'cancel_or_modify' => 'Se precisar de cancelar ou modificar a sua reserva, por favor contacte-nos através do :restaurant_phone ou :restaurant_email.',
    'contact_us' => 'Se tiver alguma questão, contacte-nos através do :restaurant_phone ou :restaurant_email.',
    'status' => 'Estado',
    'confirmed' => 'Confirmada',
    'cancelled' => 'Cancelada',
    'note_from_restaurant' => 'Nota do Restaurante',
    'make_new_reservation' => 'Fazer Nova Reserva',
    'status_update' => [
        'approved_title' => 'Reserva Confirmada!',
        'approved_message' => 'Ótimas notícias! A sua reserva foi confirmada.',
        'cancelled_title' => 'Reserva Cancelada',
        'cancelled_message' => 'Lamentamos, mas a sua reserva foi cancelada.',
        'default_title' => 'Atualização do Estado da Reserva',
        'default_message' => 'O estado da sua reserva foi atualizado.',
    ],
];
