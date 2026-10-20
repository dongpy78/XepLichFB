<?php

return [
    'new-reservation' => [
        'title' => 'नई आरक्षण अधिसूचना',
        'description' => 'नया आरक्षण प्राप्त होने पर व्यवस्थापक को भेजा गया ईमेल',
        'subject' => '{{ reservation_name }} से नया आरक्षण',
        'header' => 'नया आरक्षण प्राप्त हुआ!',
        'content' => '<p>आपको निम्नलिखित विवरण के साथ एक नया आरक्षण प्राप्त हुआ है:</p>
                     <p><strong>नाम:</strong> {{ reservation_name }}<br>
                     <strong>ईमेल:</strong> {{ reservation_email }}<br>
                     <strong>फोन:</strong> {{ reservation_phone }}<br>
                     <strong>तारीख:</strong> {{ reservation_date }}<br>
                     <strong>समय:</strong> {{ reservation_time }}<br>
                     <strong>मेहमानों की संख्या:</strong> {{ number_of_guests }}<br>
                     <strong>विशेष अनुरोध:</strong> {{ special_requests }}</p>
                     <p>इस आरक्षण को प्रबंधित करने के लिए कृपया अपने व्यवस्थापक पैनल में लॉग इन करें।</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'आरक्षण पुष्टि',
        'description' => 'आरक्षण जमा करने के बाद ग्राहक को भेजा गया ईमेल',
        'subject' => 'आपकी आरक्षण पुष्टि',
        'header' => 'आरक्षण पुष्टि',
        'content' => '<p>प्रिय {{ reservation_name }},</p>
                     <p>आपके आरक्षण के लिए धन्यवाद! हमें निम्नलिखित विवरण के साथ आपका आरक्षण अनुरोध प्राप्त हुआ है:</p>
                     <p><strong>तारीख:</strong> {{ reservation_date }}<br>
                     <strong>समय:</strong> {{ reservation_time }}<br>
                     <strong>मेहमानों की संख्या:</strong> {{ number_of_guests }}</p>
                     <p>हम आपके आरक्षण की पुष्टि करने के लिए जल्द ही आपसे संपर्क करेंगे। यदि आपके कोई प्रश्न हैं, तो कृपया हमसे संपर्क करने में संकोच न करें।</p>
                     <p>सादर,<br>रेस्तरां टीम</p>',
    ],
    'reservation-status-update' => [
        'title' => 'आरक्षण स्थिति अपडेट',
        'description' => 'आरक्षण स्थिति अपडेट होने पर ग्राहक को भेजा गया ईमेल',
        'subject' => 'आपकी आरक्षण स्थिति अपडेट की गई है',
        'header' => 'आरक्षण स्थिति अपडेट',
        'content' => '<p>प्रिय {{ reservation_name }},</p>
                     <p>आपकी आरक्षण स्थिति अपडेट की गई है: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>आरक्षण विवरण:</strong><br>
                     <strong>तारीख:</strong> {{ reservation_date }}<br>
                     <strong>समय:</strong> {{ reservation_time }}<br>
                     <strong>मेहमानों की संख्या:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>यदि आपके कोई प्रश्न हैं, तो कृपया हमसे संपर्क करें।</p>
                     <p>सादर,<br>रेस्तरां टीम</p>',
    ],
    'reservation_details' => 'आरक्षण विवरण',
    'date' => 'तारीख',
    'time' => 'समय',
    'guests' => 'मेहमान',
    'special_requests' => 'विशेष अनुरोध',
    'visit_website' => 'हमारी वेबसाइट पर जाएं',
    'view_reservation' => 'आरक्षण देखें',
    'cancel_or_modify' => 'यदि आपको अपना आरक्षण रद्द या संशोधित करने की आवश्यकता है, तो कृपया :restaurant_phone या :restaurant_email पर हमसे संपर्क करें।',
    'contact_us' => 'यदि आपके कोई प्रश्न हैं, तो कृपया :restaurant_phone या :restaurant_email पर हमसे संपर्क करें।',
    'status' => 'स्थिति',
    'confirmed' => 'पुष्टि की गई',
    'cancelled' => 'रद्द की गई',
    'note_from_restaurant' => 'रेस्तरां से नोट',
    'make_new_reservation' => 'नया आरक्षण करें',
    'status_update' => [
        'approved_title' => 'आरक्षण पुष्टि की गई!',
        'approved_message' => 'शुभ समाचार! आपका आरक्षण पुष्टि किया गया है।',
        'cancelled_title' => 'आरक्षण रद्द किया गया',
        'cancelled_message' => 'हमें खेद है, लेकिन आपका आरक्षण रद्द कर दिया गया है।',
        'default_title' => 'आरक्षण स्थिति अपडेट',
        'default_message' => 'आपकी आरक्षण स्थिति अपडेट की गई है।',
    ],
];
