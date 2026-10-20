<?php

return [
    'new-reservation' => [
        'title' => 'Notification de nouvelle réservation',
        'description' => 'Email envoyé à l\'administrateur lorsqu\'une nouvelle réservation est soumise',
        'subject' => 'Nouvelle réservation de {{ reservation_name }}',
        'header' => 'Nouvelle réservation reçue !',
        'content' => '<p>Vous avez reçu une nouvelle réservation avec les détails suivants :</p>
                     <p><strong>Nom :</strong> {{ reservation_name }}<br>
                     <strong>Email :</strong> {{ reservation_email }}<br>
                     <strong>Téléphone :</strong> {{ reservation_phone }}<br>
                     <strong>Date :</strong> {{ reservation_date }}<br>
                     <strong>Heure :</strong> {{ reservation_time }}<br>
                     <strong>Nombre d\'invités :</strong> {{ number_of_guests }}<br>
                     <strong>Demandes spéciales :</strong> {{ special_requests }}</p>
                     <p>Veuillez vous connecter à votre panneau d\'administration pour gérer cette réservation.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmation de réservation',
        'description' => 'Email envoyé au client après la soumission d\'une réservation',
        'subject' => 'Confirmation de votre réservation',
        'header' => 'Confirmation de réservation',
        'content' => '<p>Cher/Chère {{ reservation_name }},</p>
                     <p>Merci pour votre réservation ! Nous avons reçu votre demande de réservation avec les détails suivants :</p>
                     <p><strong>Date :</strong> {{ reservation_date }}<br>
                     <strong>Heure :</strong> {{ reservation_time }}<br>
                     <strong>Nombre d\'invités :</strong> {{ number_of_guests }}</p>
                     <p>Nous vous contacterons bientôt pour confirmer votre réservation. Si vous avez des questions, n\'hésitez pas à nous contacter.</p>
                     <p>Cordialement,<br>L\'équipe du restaurant</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Mise à jour du statut de réservation',
        'description' => 'Email envoyé au client lorsque le statut de la réservation est mis à jour',
        'subject' => 'Le statut de votre réservation a été mis à jour',
        'header' => 'Mise à jour du statut de réservation',
        'content' => '<p>Cher/Chère {{ reservation_name }},</p>
                     <p>Le statut de votre réservation a été mis à jour à : <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Détails de la réservation :</strong><br>
                     <strong>Date :</strong> {{ reservation_date }}<br>
                     <strong>Heure :</strong> {{ reservation_time }}<br>
                     <strong>Nombre d\'invités :</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Si vous avez des questions, veuillez nous contacter.</p>
                     <p>Cordialement,<br>L\'équipe du restaurant</p>',
    ],
    'reservation_details' => 'Détails de la réservation',
    'date' => 'Date',
    'time' => 'Heure',
    'guests' => 'Invités',
    'special_requests' => 'Demandes spéciales',
    'visit_website' => 'Visitez notre site Web',
    'view_reservation' => 'Voir la réservation',
    'cancel_or_modify' => 'Si vous devez annuler ou modifier votre réservation, veuillez nous contacter au :restaurant_phone ou :restaurant_email.',
    'contact_us' => 'Si vous avez des questions, veuillez nous contacter au :restaurant_phone ou :restaurant_email.',
    'status' => 'Statut',
    'confirmed' => 'Confirmé',
    'cancelled' => 'Annulé',
    'note_from_restaurant' => 'Note du restaurant',
    'make_new_reservation' => 'Faire une nouvelle réservation',
    'status_update' => [
        'approved_title' => 'Réservation confirmée !',
        'approved_message' => 'Bonne nouvelle ! Votre réservation a été confirmée.',
        'cancelled_title' => 'Réservation annulée',
        'cancelled_message' => 'Nous sommes désolés, mais votre réservation a été annulée.',
        'default_title' => 'Mise à jour du statut de réservation',
        'default_message' => 'Le statut de votre réservation a été mis à jour.',
    ],
];
