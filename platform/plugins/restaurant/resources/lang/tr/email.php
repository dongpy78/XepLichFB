<?php

return [
    'new-reservation' => [
        'title' => 'Yeni Rezervasyon Bildirimi',
        'description' => 'Yeni bir rezervasyon gönderildiğinde yöneticiye gönderilen e-posta',
        'subject' => '{{ reservation_name }} tarafından yeni rezervasyon',
        'header' => 'Yeni Rezervasyon Alındı!',
        'content' => '<p>Aşağıdaki detaylarla yeni bir rezervasyon aldınız:</p>
                     <p><strong>Ad:</strong> {{ reservation_name }}<br>
                     <strong>E-posta:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Tarih:</strong> {{ reservation_date }}<br>
                     <strong>Saat:</strong> {{ reservation_time }}<br>
                     <strong>Misafir Sayısı:</strong> {{ number_of_guests }}<br>
                     <strong>Özel İstekler:</strong> {{ special_requests }}</p>
                     <p>Bu rezervasyonu yönetmek için lütfen yönetici panelinize giriş yapın.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Rezervasyon Onayı',
        'description' => 'Rezervasyon gönderdikten sonra müşteriye gönderilen e-posta',
        'subject' => 'Rezervasyon onayınız',
        'header' => 'Rezervasyon Onayı',
        'content' => '<p>Sayın {{ reservation_name }},</p>
                     <p>Rezervasyonunuz için teşekkür ederiz! Aşağıdaki detaylarla rezervasyon talebinizi aldık:</p>
                     <p><strong>Tarih:</strong> {{ reservation_date }}<br>
                     <strong>Saat:</strong> {{ reservation_time }}<br>
                     <strong>Misafir Sayısı:</strong> {{ number_of_guests }}</p>
                     <p>Rezervasyonunuzu onaylamak için yakında sizinle iletişime geçeceğiz. Herhangi bir sorunuz varsa, lütfen bizimle iletişime geçmekten çekinmeyin.</p>
                     <p>Saygılarımızla,<br>Restoran Ekibi</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Rezervasyon Durumu Güncellemesi',
        'description' => 'Rezervasyon durumu güncellendiğinde müşteriye gönderilen e-posta',
        'subject' => 'Rezervasyon durumunuz güncellendi',
        'header' => 'Rezervasyon Durumu Güncellemesi',
        'content' => '<p>Sayın {{ reservation_name }},</p>
                     <p>Rezervasyon durumunuz şu şekilde güncellendi: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Rezervasyon Detayları:</strong><br>
                     <strong>Tarih:</strong> {{ reservation_date }}<br>
                     <strong>Saat:</strong> {{ reservation_time }}<br>
                     <strong>Misafir Sayısı:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Herhangi bir sorunuz varsa, lütfen bizimle iletişime geçin.</p>
                     <p>Saygılarımızla,<br>Restoran Ekibi</p>',
    ],
    'reservation_details' => 'Rezervasyon Detayları',
    'date' => 'Tarih',
    'time' => 'Saat',
    'guests' => 'Misafirler',
    'special_requests' => 'Özel İstekler',
    'visit_website' => 'Web Sitemizi Ziyaret Edin',
    'view_reservation' => 'Rezervasyonu Görüntüle',
    'cancel_or_modify' => 'Rezervasyonunuzu iptal etmeniz veya değiştirmeniz gerekirse, lütfen :restaurant_phone veya :restaurant_email üzerinden bizimle iletişime geçin.',
    'contact_us' => 'Herhangi bir sorunuz varsa, lütfen :restaurant_phone veya :restaurant_email üzerinden bizimle iletişime geçin.',
    'status' => 'Durum',
    'confirmed' => 'Onaylandı',
    'cancelled' => 'İptal edildi',
    'note_from_restaurant' => 'Restorandan Not',
    'make_new_reservation' => 'Yeni Rezervasyon Yap',
    'status_update' => [
        'approved_title' => 'Rezervasyon Onaylandı!',
        'approved_message' => 'Harika haber! Rezervasyonunuz onaylandı.',
        'cancelled_title' => 'Rezervasyon İptal Edildi',
        'cancelled_message' => 'Üzgünüz, ancak rezervasyonunuz iptal edildi.',
        'default_title' => 'Rezervasyon Durumu Güncellemesi',
        'default_message' => 'Rezervasyon durumunuz güncellendi.',
    ],
];
