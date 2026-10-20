<?php

return [
    'new-reservation' => [
        'title' => 'Ειδοποίηση Νέας Κράτησης',
        'description' => 'Email που αποστέλλεται στον διαχειριστή όταν υποβάλλεται νέα κράτηση',
        'subject' => 'Νέα κράτηση από {{ reservation_name }}',
        'header' => 'Νέα Κράτηση Ελήφθη!',
        'content' => '<p>Λάβατε νέα κράτηση με τα ακόλουθα στοιχεία:</p>
                     <p><strong>Όνομα:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Τηλέφωνο:</strong> {{ reservation_phone }}<br>
                     <strong>Ημερομηνία:</strong> {{ reservation_date }}<br>
                     <strong>Ώρα:</strong> {{ reservation_time }}<br>
                     <strong>Αριθμός Επισκεπτών:</strong> {{ number_of_guests }}<br>
                     <strong>Ειδικά Αιτήματα:</strong> {{ special_requests }}</p>
                     <p>Παρακαλώ συνδεθείτε στον πίνακα διαχείρισης για να διαχειριστείτε αυτήν την κράτηση.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Επιβεβαίωση Κράτησης',
        'description' => 'Email που αποστέλλεται στον πελάτη μετά την υποβολή κράτησης',
        'subject' => 'Επιβεβαίωση της κράτησής σας',
        'header' => 'Επιβεβαίωση Κράτησης',
        'content' => '<p>Αγαπητέ/ή {{ reservation_name }},</p>
                     <p>Σας ευχαριστούμε για την κράτησή σας! Λάβαμε το αίτημα κράτησής σας με τα ακόλουθα στοιχεία:</p>
                     <p><strong>Ημερομηνία:</strong> {{ reservation_date }}<br>
                     <strong>Ώρα:</strong> {{ reservation_time }}<br>
                     <strong>Αριθμός Επισκεπτών:</strong> {{ number_of_guests }}</p>
                     <p>Θα επικοινωνήσουμε σύντομα μαζί σας για να επιβεβαιώσουμε την κράτησή σας. Αν έχετε ερωτήσεις, μη διστάσετε να επικοινωνήσετε μαζί μας.</p>
                     <p>Με εκτίμηση,<br>Η Ομάδα του Εστιατορίου</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Ενημέρωση Κατάστασης Κράτησης',
        'description' => 'Email που αποστέλλεται στον πελάτη όταν ενημερώνεται η κατάσταση κράτησης',
        'subject' => 'Η κατάσταση της κράτησής σας ενημερώθηκε',
        'header' => 'Ενημέρωση Κατάστασης Κράτησης',
        'content' => '<p>Αγαπητέ/ή {{ reservation_name }},</p>
                     <p>Η κατάσταση της κράτησής σας ενημερώθηκε σε: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Στοιχεία Κράτησης:</strong><br>
                     <strong>Ημερομηνία:</strong> {{ reservation_date }}<br>
                     <strong>Ώρα:</strong> {{ reservation_time }}<br>
                     <strong>Αριθμός Επισκεπτών:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Αν έχετε ερωτήσεις, παρακαλώ επικοινωνήστε μαζί μας.</p>
                     <p>Με εκτίμηση,<br>Η Ομάδα του Εστιατορίου</p>',
    ],
    'reservation_details' => 'Στοιχεία Κράτησης',
    'date' => 'Ημερομηνία',
    'time' => 'Ώρα',
    'guests' => 'Επισκέπτες',
    'special_requests' => 'Ειδικά Αιτήματα',
    'visit_website' => 'Επισκεφθείτε τον Ιστότοπό μας',
    'view_reservation' => 'Προβολή Κράτησης',
    'cancel_or_modify' => 'Αν χρειάζεται να ακυρώσετε ή να τροποποιήσετε την κράτησή σας, παρακαλώ επικοινωνήστε μαζί μας στο :restaurant_phone ή :restaurant_email.',
    'contact_us' => 'Αν έχετε ερωτήσεις, παρακαλώ επικοινωνήστε μαζί μας στο :restaurant_phone ή :restaurant_email.',
    'status' => 'Κατάσταση',
    'confirmed' => 'Επιβεβαιωμένη',
    'cancelled' => 'Ακυρωμένη',
    'note_from_restaurant' => 'Σημείωση από το Εστιατόριο',
    'make_new_reservation' => 'Νέα Κράτηση',
    'status_update' => [
        'approved_title' => 'Κράτηση Επιβεβαιώθηκε!',
        'approved_message' => 'Εξαιρετικά νέα! Η κράτησή σας επιβεβαιώθηκε.',
        'cancelled_title' => 'Κράτηση Ακυρώθηκε',
        'cancelled_message' => 'Λυπούμαστε, αλλά η κράτησή σας ακυρώθηκε.',
        'default_title' => 'Ενημέρωση Κατάστασης Κράτησης',
        'default_message' => 'Η κατάσταση της κράτησής σας ενημερώθηκε.',
    ],
];
