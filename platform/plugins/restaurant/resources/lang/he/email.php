<?php

return [
    'new-reservation' => [
        'title' => 'הודעת הזמנה חדשה',
        'description' => 'אימייל הנשלח למנהל כאשר נשלחת הזמנה חדשה',
        'subject' => 'הזמנה חדשה מאת {{ reservation_name }}',
        'header' => 'התקבלה הזמנה חדשה!',
        'content' => '<p>קיבלת הזמנה חדשה עם הפרטים הבאים:</p>
                     <p><strong>שם:</strong> {{ reservation_name }}<br>
                     <strong>אימייל:</strong> {{ reservation_email }}<br>
                     <strong>טלפון:</strong> {{ reservation_phone }}<br>
                     <strong>תאריך:</strong> {{ reservation_date }}<br>
                     <strong>שעה:</strong> {{ reservation_time }}<br>
                     <strong>מספר אורחים:</strong> {{ number_of_guests }}<br>
                     <strong>בקשות מיוחדות:</strong> {{ special_requests }}</p>
                     <p>אנא התחבר לפאנל הניהול שלך כדי לנהל את ההזמנה הזו.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'אישור הזמנה',
        'description' => 'אימייל הנשלח ללקוח לאחר שליחת הזמנה',
        'subject' => 'אישור ההזמנה שלך',
        'header' => 'אישור הזמנה',
        'content' => '<p>{{ reservation_name }} יקר/ה,</p>
                     <p>תודה על ההזמנה שלך! קיבלנו את בקשת ההזמנה שלך עם הפרטים הבאים:</p>
                     <p><strong>תאריך:</strong> {{ reservation_date }}<br>
                     <strong>שעה:</strong> {{ reservation_time }}<br>
                     <strong>מספר אורחים:</strong> {{ number_of_guests }}</p>
                     <p>ניצור איתך קשר בקרוב כדי לאשר את ההזמנה שלך. אם יש לך שאלות, אל תהסס לפנות אלינו.</p>
                     <p>בברכה,<br>צוות המסעדה</p>',
    ],
    'reservation-status-update' => [
        'title' => 'עדכון סטטוס הזמנה',
        'description' => 'אימייל הנשלח ללקוח כאשר סטטוס ההזמנה מתעדכן',
        'subject' => 'סטטוס ההזמנה שלך עודכן',
        'header' => 'עדכון סטטוס הזמנה',
        'content' => '<p>{{ reservation_name }} יקר/ה,</p>
                     <p>סטטוס ההזמנה שלך עודכן ל: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>פרטי ההזמנה:</strong><br>
                     <strong>תאריך:</strong> {{ reservation_date }}<br>
                     <strong>שעה:</strong> {{ reservation_time }}<br>
                     <strong>מספר אורחים:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>אם יש לך שאלות, אנא צור איתנו קשר.</p>
                     <p>בברכה,<br>צוות המסעדה</p>',
    ],
    'reservation_details' => 'פרטי הזמנה',
    'date' => 'תאריך',
    'time' => 'שעה',
    'guests' => 'אורחים',
    'special_requests' => 'בקשות מיוחדות',
    'visit_website' => 'בקר באתר שלנו',
    'view_reservation' => 'צפה בהזמנה',
    'cancel_or_modify' => 'אם אתה צריך לבטל או לשנות את ההזמנה שלך, אנא צור איתנו קשר ב-:restaurant_phone או :restaurant_email.',
    'contact_us' => 'אם יש לך שאלות, אנא צור איתנו קשר ב-:restaurant_phone או :restaurant_email.',
    'status' => 'סטטוס',
    'confirmed' => 'אושר',
    'cancelled' => 'בוטל',
    'note_from_restaurant' => 'הערה מהמסעדה',
    'make_new_reservation' => 'צור הזמנה חדשה',
    'status_update' => [
        'approved_title' => 'ההזמנה אושרה!',
        'approved_message' => 'חדשות טובות! ההזמנה שלך אושרה.',
        'cancelled_title' => 'ההזמנה בוטלה',
        'cancelled_message' => 'אנו מצטערים, אך ההזמנה שלך בוטלה.',
        'default_title' => 'עדכון סטטוס הזמנה',
        'default_message' => 'סטטוס ההזמנה שלך עודכן.',
    ],
];
