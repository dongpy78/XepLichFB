<?php

return [
    'new-reservation' => [
        'title' => 'Pemberitahuan Tempahan Baharu',
        'description' => 'E-mel dihantar kepada admin apabila tempahan baharu dikemukakan',
        'subject' => 'Tempahan baharu daripada {{ reservation_name }}',
        'header' => 'Tempahan Baharu Diterima!',
        'content' => '<p>Anda telah menerima tempahan baharu dengan butiran berikut:</p>
                     <p><strong>Nama:</strong> {{ reservation_name }}<br>
                     <strong>E-mel:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Tarikh:</strong> {{ reservation_date }}<br>
                     <strong>Masa:</strong> {{ reservation_time }}<br>
                     <strong>Bilangan Tetamu:</strong> {{ number_of_guests }}<br>
                     <strong>Permintaan Khas:</strong> {{ special_requests }}</p>
                     <p>Sila log masuk ke panel admin anda untuk menguruskan tempahan ini.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Pengesahan Tempahan',
        'description' => 'E-mel dihantar kepada pelanggan selepas mengemukakan tempahan',
        'subject' => 'Pengesahan tempahan anda',
        'header' => 'Pengesahan Tempahan',
        'content' => '<p>Yang Berhormat {{ reservation_name }},</p>
                     <p>Terima kasih atas tempahan anda! Kami telah menerima permintaan tempahan anda dengan butiran berikut:</p>
                     <p><strong>Tarikh:</strong> {{ reservation_date }}<br>
                     <strong>Masa:</strong> {{ reservation_time }}<br>
                     <strong>Bilangan Tetamu:</strong> {{ number_of_guests }}</p>
                     <p>Kami akan menghubungi anda tidak lama lagi untuk mengesahkan tempahan anda. Jika anda mempunyai sebarang pertanyaan, jangan teragak-agak untuk menghubungi kami.</p>
                     <p>Yang benar,<br>Pasukan Restoran</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Kemas Kini Status Tempahan',
        'description' => 'E-mel dihantar kepada pelanggan apabila status tempahan dikemas kini',
        'subject' => 'Status tempahan anda telah dikemas kini',
        'header' => 'Kemas Kini Status Tempahan',
        'content' => '<p>Yang Berhormat {{ reservation_name }},</p>
                     <p>Status tempahan anda telah dikemas kini kepada: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Butiran Tempahan:</strong><br>
                     <strong>Tarikh:</strong> {{ reservation_date }}<br>
                     <strong>Masa:</strong> {{ reservation_time }}<br>
                     <strong>Bilangan Tetamu:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Jika anda mempunyai sebarang pertanyaan, sila hubungi kami.</p>
                     <p>Yang benar,<br>Pasukan Restoran</p>',
    ],
    'reservation_details' => 'Butiran Tempahan',
    'date' => 'Tarikh',
    'time' => 'Masa',
    'guests' => 'Tetamu',
    'special_requests' => 'Permintaan Khas',
    'visit_website' => 'Lawati Laman Web Kami',
    'view_reservation' => 'Lihat Tempahan',
    'cancel_or_modify' => 'Jika anda perlu membatalkan atau mengubah tempahan anda, sila hubungi kami di :restaurant_phone atau :restaurant_email.',
    'contact_us' => 'Jika anda mempunyai sebarang pertanyaan, sila hubungi kami di :restaurant_phone atau :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Disahkan',
    'cancelled' => 'Dibatalkan',
    'note_from_restaurant' => 'Nota daripada Restoran',
    'make_new_reservation' => 'Buat Tempahan Baharu',
    'status_update' => [
        'approved_title' => 'Tempahan Disahkan!',
        'approved_message' => 'Berita baik! Tempahan anda telah disahkan.',
        'cancelled_title' => 'Tempahan Dibatalkan',
        'cancelled_message' => 'Kami minta maaf, tetapi tempahan anda telah dibatalkan.',
        'default_title' => 'Kemas Kini Status Tempahan',
        'default_message' => 'Status tempahan anda telah dikemas kini.',
    ],
];
