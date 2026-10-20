<?php

return [
    'new-reservation' => [
        'title' => 'নতুন রিজার্ভেশন বিজ্ঞপ্তি',
        'description' => 'নতুন রিজার্ভেশন জমা দেওয়া হলে অ্যাডমিনকে ইমেল পাঠানো হয়',
        'subject' => '{{ reservation_name }} থেকে নতুন রিজার্ভেশন',
        'header' => 'নতুন রিজার্ভেশন পাওয়া গেছে!',
        'content' => '<p>আপনি নিম্নলিখিত বিবরণ সহ একটি নতুন রিজার্ভেশন পেয়েছেন:</p>
                     <p><strong>নাম:</strong> {{ reservation_name }}<br>
                     <strong>ইমেল:</strong> {{ reservation_email }}<br>
                     <strong>ফোন:</strong> {{ reservation_phone }}<br>
                     <strong>তারিখ:</strong> {{ reservation_date }}<br>
                     <strong>সময়:</strong> {{ reservation_time }}<br>
                     <strong>অতিথি সংখ্যা:</strong> {{ number_of_guests }}<br>
                     <strong>বিশেষ অনুরোধ:</strong> {{ special_requests }}</p>
                     <p>এই রিজার্ভেশন পরিচালনা করতে অনুগ্রহ করে আপনার অ্যাডমিন প্যানেলে লগইন করুন।</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'রিজার্ভেশন নিশ্চিতকরণ',
        'description' => 'রিজার্ভেশন জমা দেওয়ার পর গ্রাহককে ইমেল পাঠানো হয়',
        'subject' => 'আপনার রিজার্ভেশন নিশ্চিতকরণ',
        'header' => 'রিজার্ভেশন নিশ্চিতকরণ',
        'content' => '<p>প্রিয় {{ reservation_name }},</p>
                     <p>আপনার রিজার্ভেশনের জন্য ধন্যবাদ! আমরা নিম্নলিখিত বিবরণ সহ আপনার বুকিং অনুরোধ পেয়েছি:</p>
                     <p><strong>তারিখ:</strong> {{ reservation_date }}<br>
                     <strong>সময়:</strong> {{ reservation_time }}<br>
                     <strong>অতিথি সংখ্যা:</strong> {{ number_of_guests }}</p>
                     <p>আমরা শীঘ্রই আপনার রিজার্ভেশন নিশ্চিত করতে আপনার সাথে যোগাযোগ করব। যদি আপনার কোন প্রশ্ন থাকে, অনুগ্রহ করে আমাদের সাথে যোগাযোগ করতে দ্বিধা করবেন না।</p>
                     <p>শুভেচ্ছা সহ,<br>রেস্টুরেন্ট টিম</p>',
    ],
    'reservation-status-update' => [
        'title' => 'রিজার্ভেশন স্ট্যাটাস আপডেট',
        'description' => 'রিজার্ভেশন স্ট্যাটাস আপডেট হলে গ্রাহককে ইমেল পাঠানো হয়',
        'subject' => 'আপনার রিজার্ভেশন স্ট্যাটাস আপডেট করা হয়েছে',
        'header' => 'রিজার্ভেশন স্ট্যাটাস আপডেট',
        'content' => '<p>প্রিয় {{ reservation_name }},</p>
                     <p>আপনার রিজার্ভেশন স্ট্যাটাস আপডেট করা হয়েছে: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>রিজার্ভেশন বিবরণ:</strong><br>
                     <strong>তারিখ:</strong> {{ reservation_date }}<br>
                     <strong>সময়:</strong> {{ reservation_time }}<br>
                     <strong>অতিথি সংখ্যা:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>যদি আপনার কোন প্রশ্ন থাকে, অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন।</p>
                     <p>শুভেচ্ছা সহ,<br>রেস্টুরেন্ট টিম</p>',
    ],
    'reservation_details' => 'রিজার্ভেশন বিবরণ',
    'date' => 'তারিখ',
    'time' => 'সময়',
    'guests' => 'অতিথি',
    'special_requests' => 'বিশেষ অনুরোধ',
    'visit_website' => 'আমাদের ওয়েবসাইট দেখুন',
    'view_reservation' => 'রিজার্ভেশন দেখুন',
    'cancel_or_modify' => 'যদি আপনার রিজার্ভেশন বাতিল বা পরিবর্তন করার প্রয়োজন হয়, অনুগ্রহ করে :restaurant_phone বা :restaurant_email এ আমাদের সাথে যোগাযোগ করুন।',
    'contact_us' => 'যদি আপনার কোন প্রশ্ন থাকে, অনুগ্রহ করে :restaurant_phone বা :restaurant_email এ আমাদের সাথে যোগাযোগ করুন।',
    'status' => 'স্ট্যাটাস',
    'confirmed' => 'নিশ্চিত',
    'cancelled' => 'বাতিল',
    'note_from_restaurant' => 'রেস্টুরেন্ট থেকে নোট',
    'make_new_reservation' => 'নতুন রিজার্ভেশন করুন',
    'status_update' => [
        'approved_title' => 'রিজার্ভেশন নিশ্চিত!',
        'approved_message' => 'সুখবর! আপনার রিজার্ভেশন নিশ্চিত করা হয়েছে।',
        'cancelled_title' => 'রিজার্ভেশন বাতিল',
        'cancelled_message' => 'আমরা দুঃখিত, কিন্তু আপনার রিজার্ভেশন বাতিল করা হয়েছে।',
        'default_title' => 'রিজার্ভেশন স্ট্যাটাস আপডেট',
        'default_message' => 'আপনার রিজার্ভেশন স্ট্যাটাস আপডেট করা হয়েছে।',
    ],
];
