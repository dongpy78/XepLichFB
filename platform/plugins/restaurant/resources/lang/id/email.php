<?php

return [
    'new-reservation' => [
        'title' => 'Notifikasi Reservasi Baru',
        'description' => 'Email dikirim ke admin saat reservasi baru diterima',
        'subject' => 'Reservasi baru dari {{ reservation_name }}',
        'header' => 'Reservasi Baru Diterima!',
        'content' => '<p>Anda telah menerima reservasi baru dengan detail berikut:</p>
                     <p><strong>Nama:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Telepon:</strong> {{ reservation_phone }}<br>
                     <strong>Tanggal:</strong> {{ reservation_date }}<br>
                     <strong>Waktu:</strong> {{ reservation_time }}<br>
                     <strong>Jumlah Tamu:</strong> {{ number_of_guests }}<br>
                     <strong>Permintaan Khusus:</strong> {{ special_requests }}</p>
                     <p>Silakan masuk ke panel admin Anda untuk mengelola reservasi ini.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Konfirmasi Reservasi',
        'description' => 'Email dikirim ke pelanggan setelah mengirim reservasi',
        'subject' => 'Konfirmasi reservasi Anda',
        'header' => 'Konfirmasi Reservasi',
        'content' => '<p>Yth. {{ reservation_name }},</p>
                     <p>Terima kasih atas reservasi Anda! Kami telah menerima permintaan reservasi Anda dengan detail berikut:</p>
                     <p><strong>Tanggal:</strong> {{ reservation_date }}<br>
                     <strong>Waktu:</strong> {{ reservation_time }}<br>
                     <strong>Jumlah Tamu:</strong> {{ number_of_guests }}</p>
                     <p>Kami akan segera menghubungi Anda untuk mengkonfirmasi reservasi Anda. Jika Anda memiliki pertanyaan, jangan ragu untuk menghubungi kami.</p>
                     <p>Salam hormat,<br>Tim Restoran</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Pembaruan Status Reservasi',
        'description' => 'Email dikirim ke pelanggan saat status reservasi diperbarui',
        'subject' => 'Status reservasi Anda telah diperbarui',
        'header' => 'Pembaruan Status Reservasi',
        'content' => '<p>Yth. {{ reservation_name }},</p>
                     <p>Status reservasi Anda telah diperbarui menjadi: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detail Reservasi:</strong><br>
                     <strong>Tanggal:</strong> {{ reservation_date }}<br>
                     <strong>Waktu:</strong> {{ reservation_time }}<br>
                     <strong>Jumlah Tamu:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Jika Anda memiliki pertanyaan, silakan hubungi kami.</p>
                     <p>Salam hormat,<br>Tim Restoran</p>',
    ],
    'reservation_details' => 'Detail Reservasi',
    'date' => 'Tanggal',
    'time' => 'Waktu',
    'guests' => 'Tamu',
    'special_requests' => 'Permintaan Khusus',
    'visit_website' => 'Kunjungi Website Kami',
    'view_reservation' => 'Lihat Reservasi',
    'cancel_or_modify' => 'Jika Anda perlu membatalkan atau mengubah reservasi Anda, silakan hubungi kami di :restaurant_phone atau :restaurant_email.',
    'contact_us' => 'Jika Anda memiliki pertanyaan, silakan hubungi kami di :restaurant_phone atau :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Dikonfirmasi',
    'cancelled' => 'Dibatalkan',
    'note_from_restaurant' => 'Catatan dari Restoran',
    'make_new_reservation' => 'Buat Reservasi Baru',
    'status_update' => [
        'approved_title' => 'Reservasi Dikonfirmasi!',
        'approved_message' => 'Kabar baik! Reservasi Anda telah dikonfirmasi.',
        'cancelled_title' => 'Reservasi Dibatalkan',
        'cancelled_message' => 'Kami mohon maaf, tetapi reservasi Anda telah dibatalkan.',
        'default_title' => 'Pembaruan Status Reservasi',
        'default_message' => 'Status reservasi Anda telah diperbarui.',
    ],
];
