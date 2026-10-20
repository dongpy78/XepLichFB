<?php

return [
    'new-reservation' => [
        'title' => 'إشعار حجز جديد',
        'description' => 'بريد إلكتروني يتم إرساله للمسؤول عند تقديم حجز جديد',
        'subject' => 'حجز جديد من {{ reservation_name }}',
        'header' => 'تم استلام حجز جديد!',
        'content' => '<p>لقد استلمت حجزًا جديدًا بالتفاصيل التالية:</p>
                     <p><strong>الاسم:</strong> {{ reservation_name }}<br>
                     <strong>البريد الإلكتروني:</strong> {{ reservation_email }}<br>
                     <strong>الهاتف:</strong> {{ reservation_phone }}<br>
                     <strong>التاريخ:</strong> {{ reservation_date }}<br>
                     <strong>الوقت:</strong> {{ reservation_time }}<br>
                     <strong>عدد الضيوف:</strong> {{ number_of_guests }}<br>
                     <strong>الطلبات الخاصة:</strong> {{ special_requests }}</p>
                     <p>يرجى تسجيل الدخول إلى لوحة الإدارة لإدارة هذا الحجز.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'تأكيد الحجز',
        'description' => 'بريد إلكتروني يتم إرساله للعميل بعد تقديم الحجز',
        'subject' => 'تأكيد حجزك',
        'header' => 'تأكيد الحجز',
        'content' => '<p>عزيزي {{ reservation_name }}،</p>
                     <p>شكرًا لك على حجزك! لقد استلمنا طلب حجزك بالتفاصيل التالية:</p>
                     <p><strong>التاريخ:</strong> {{ reservation_date }}<br>
                     <strong>الوقت:</strong> {{ reservation_time }}<br>
                     <strong>عدد الضيوف:</strong> {{ number_of_guests }}</p>
                     <p>سنتواصل معك قريبًا لتأكيد حجزك. إذا كان لديك أي أسئلة، فلا تتردد في الاتصال بنا.</p>
                     <p>مع أطيب التحيات،<br>فريق المطعم</p>',
    ],
    'reservation-status-update' => [
        'title' => 'تحديث حالة الحجز',
        'description' => 'بريد إلكتروني يتم إرساله للعميل عند تحديث حالة الحجز',
        'subject' => 'تم تحديث حالة حجزك',
        'header' => 'تحديث حالة الحجز',
        'content' => '<p>عزيزي {{ reservation_name }}،</p>
                     <p>تم تحديث حالة حجزك إلى: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>تفاصيل الحجز:</strong><br>
                     <strong>التاريخ:</strong> {{ reservation_date }}<br>
                     <strong>الوقت:</strong> {{ reservation_time }}<br>
                     <strong>عدد الضيوف:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>إذا كان لديك أي أسئلة، يرجى الاتصال بنا.</p>
                     <p>مع أطيب التحيات،<br>فريق المطعم</p>',
    ],
    'reservation_details' => 'تفاصيل الحجز',
    'date' => 'التاريخ',
    'time' => 'الوقت',
    'guests' => 'الضيوف',
    'special_requests' => 'الطلبات الخاصة',
    'visit_website' => 'زيارة موقعنا',
    'view_reservation' => 'عرض الحجز',
    'cancel_or_modify' => 'إذا كنت بحاجة إلى إلغاء أو تعديل حجزك، يرجى الاتصال بنا على :restaurant_phone أو :restaurant_email.',
    'contact_us' => 'إذا كان لديك أي أسئلة، يرجى الاتصال بنا على :restaurant_phone أو :restaurant_email.',
    'status' => 'الحالة',
    'confirmed' => 'مؤكد',
    'cancelled' => 'ملغي',
    'note_from_restaurant' => 'ملاحظة من المطعم',
    'make_new_reservation' => 'إجراء حجز جديد',
    'status_update' => [
        'approved_title' => 'تم تأكيد الحجز!',
        'approved_message' => 'أخبار رائعة! تم تأكيد حجزك.',
        'cancelled_title' => 'تم إلغاء الحجز',
        'cancelled_message' => 'نأسف، ولكن تم إلغاء حجزك.',
        'default_title' => 'تحديث حالة الحجز',
        'default_message' => 'تم تحديث حالة حجزك.',
    ],
];
