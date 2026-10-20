<?php

return [
    'menu_name' => 'Restaurant',
    'name' => 'Restaurant',
    'booking_calendar' => 'Calendrier des réservations',
    'reservation_detail' => 'Détails de la réservation',
    'form' => [
        'name' => 'Nom',
        'email' => 'Email',
        'phone' => 'Téléphone',
        'reservation_date' => 'Date de réservation',
        'reservation_time' => 'Heure de réservation',
        'guests' => 'Invités',
        'status' => 'Statut',
        'special_requests' => 'Demandes spéciales',
    ],
    'settings' => [
        'title' => 'Paramètres',
        'description' => 'Configurer les paramètres et préférences du restaurant',
        'menu_title' => 'Paramètres',
        'reservation_time_format' => 'Format de l\'heure de réservation',
        '12_hours_format' => '12 heures (AM/PM)',
        '24_hours_format' => '24 heures',
        'reservation_time_format_helper' => 'Choisissez le format d\'heure pour la sélection de l\'heure de réservation',
        'email' => [
            'templates' => [
                'title' => 'Modèles d\'email du restaurant',
                'description' => 'Configurer les modèles d\'email pour les réservations et notifications du restaurant',
            ],
        ],
    ],
    'not_available' => 'Non disponible',
    'delete_confirm_title' => 'Confirmer la suppression',
    'delete_confirm_message' => 'Êtes-vous sûr de vouloir supprimer cette réservation ? Cette action ne peut pas être annulée.',
    'delete' => 'Supprimer',
];
