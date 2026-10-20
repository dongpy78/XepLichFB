<?php

return [
    'new-reservation' => [
        'title' => 'ახალი დაჯავშნის შეტყობინება',
        'description' => 'ელ.ფოსტა, რომელიც იგზავნება ადმინისთვის ახალი დაჯავშნის გაგზავნისას',
        'subject' => 'ახალი დაჯავშნა {{ reservation_name }}-სგან',
        'header' => 'ახალი დაჯავშნა მიღებულია!',
        'content' => '<p>თქვენ მიიღეთ ახალი დაჯავშნა შემდეგი დეტალებით:</p>
                     <p><strong>სახელი:</strong> {{ reservation_name }}<br>
                     <strong>ელ.ფოსტა:</strong> {{ reservation_email }}<br>
                     <strong>ტელეფონი:</strong> {{ reservation_phone }}<br>
                     <strong>თარიღი:</strong> {{ reservation_date }}<br>
                     <strong>დრო:</strong> {{ reservation_time }}<br>
                     <strong>სტუმრების რაოდენობა:</strong> {{ number_of_guests }}<br>
                     <strong>სპეციალური მოთხოვნები:</strong> {{ special_requests }}</p>
                     <p>გთხოვთ, შეხვიდეთ თქვენს ადმინისტრატორის პანელში ამ დაჯავშნის მართვისთვის.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'დაჯავშნის დადასტურება',
        'description' => 'ელ.ფოსტა, რომელიც იგზავნება მომხმარებლისთვის დაჯავშნის გაგზავნის შემდეგ',
        'subject' => 'თქვენი დაჯავშნის დადასტურება',
        'header' => 'დაჯავშნის დადასტურება',
        'content' => '<p>პატივცემულო {{ reservation_name }},</p>
                     <p>გმადლობთ თქვენი დაჯავშნისთვის! ჩვენ მივიღეთ თქვენი დაჯავშნის მოთხოვნა შემდეგი დეტალებით:</p>
                     <p><strong>თარიღი:</strong> {{ reservation_date }}<br>
                     <strong>დრო:</strong> {{ reservation_time }}<br>
                     <strong>სტუმრების რაოდენობა:</strong> {{ number_of_guests }}</p>
                     <p>ჩვენ მალე დაგიკავშირდებით თქვენი დაჯავშნის დასადასტურებლად. თუ თქვენ გაქვთ რაიმე კითხვები, გთხოვთ არ გაგვიჭირდეთ დაგვიკავშირება.</p>
                     <p>პატივისცემით,<br>რესტორნის გუნდი</p>',
    ],
    'reservation-status-update' => [
        'title' => 'დაჯავშნის სტატუსის განახლება',
        'description' => 'ელ.ფოსტა, რომელიც იგზავნება მომხმარებლისთვის დაჯავშნის სტატუსის განახლებისას',
        'subject' => 'თქვენი დაჯავშნის სტატუსი განახლდა',
        'header' => 'დაჯავშნის სტატუსის განახლება',
        'content' => '<p>პატივცემულო {{ reservation_name }},</p>
                     <p>თქვენი დაჯავშნის სტატუსი განახლდა: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>დაჯავშნის დეტალები:</strong><br>
                     <strong>თარიღი:</strong> {{ reservation_date }}<br>
                     <strong>დრო:</strong> {{ reservation_time }}<br>
                     <strong>სტუმრების რაოდენობა:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>თუ თქვენ გაქვთ რაიმე კითხვები, გთხოვთ დაგვიკავშირდით.</p>
                     <p>პატივისცემით,<br>რესტორნის გუნდი</p>',
    ],
    'reservation_details' => 'დაჯავშნის დეტალები',
    'date' => 'თარიღი',
    'time' => 'დრო',
    'guests' => 'სტუმრები',
    'special_requests' => 'სპეციალური მოთხოვნები',
    'visit_website' => 'ეწვიეთ ჩვენს ვებსაიტს',
    'view_reservation' => 'დაჯავშნის ნახვა',
    'cancel_or_modify' => 'თუ თქვენ გჭირდებათ თქვენი დაჯავშნის გაუქმება ან შეცვლა, გთხოვთ დაგვიკავშირდით :restaurant_phone ან :restaurant_email.',
    'contact_us' => 'თუ თქვენ გაქვთ რაიმე კითხვები, გთხოვთ დაგვიკავშირდით :restaurant_phone ან :restaurant_email.',
    'status' => 'სტატუსი',
    'confirmed' => 'დადასტურებული',
    'cancelled' => 'გაუქმებული',
    'note_from_restaurant' => 'შენიშვნა რესტორნისგან',
    'make_new_reservation' => 'ახალი დაჯავშნის გაკეთება',
    'status_update' => [
        'approved_title' => 'დაჯავშნა დადასტურებულია!',
        'approved_message' => 'მშვენიერი ამბავი! თქვენი დაჯავშნა დადასტურდა.',
        'cancelled_title' => 'დაჯავშნა გაუქმებულია',
        'cancelled_message' => 'ვწუხვართ, მაგრამ თქვენი დაჯავშნა გაუქმდა.',
        'default_title' => 'დაჯავშნის სტატუსის განახლება',
        'default_message' => 'თქვენი დაჯავშნის სტატუსი განახლდა.',
    ],
];
