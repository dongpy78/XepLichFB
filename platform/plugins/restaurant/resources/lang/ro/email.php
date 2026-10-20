<?php

return [
    'new-reservation' => [
        'title' => 'Notificare Rezervare Nouă',
        'description' => 'Email trimis administratorului când o rezervare nouă este trimisă',
        'subject' => 'Rezervare nouă de la {{ reservation_name }}',
        'header' => 'Rezervare Nouă Primită!',
        'content' => '<p>Ați primit o rezervare nouă cu următoarele detalii:</p>
                     <p><strong>Nume:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Număr de Oaspeți:</strong> {{ number_of_guests }}<br>
                     <strong>Cereri Speciale:</strong> {{ special_requests }}</p>
                     <p>Vă rugăm să vă autentificați în panoul de administrare pentru a gestiona această rezervare.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmare Rezervare',
        'description' => 'Email trimis clientului după trimiterea unei rezervări',
        'subject' => 'Confirmarea rezervării dvs.',
        'header' => 'Confirmare Rezervare',
        'content' => '<p>Stimată/Stimate {{ reservation_name }},</p>
                     <p>Vă mulțumim pentru rezervare! Am primit cererea dvs. de rezervare cu următoarele detalii:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Număr de Oaspeți:</strong> {{ number_of_guests }}</p>
                     <p>Vă vom contacta în curând pentru a confirma rezervarea. Dacă aveți întrebări, nu ezitați să ne contactați.</p>
                     <p>Cu stimă,<br>Echipa Restaurantului</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Actualizare Status Rezervare',
        'description' => 'Email trimis clientului când statusul rezervării este actualizat',
        'subject' => 'Statusul rezervării dvs. a fost actualizat',
        'header' => 'Actualizare Status Rezervare',
        'content' => '<p>Stimată/Stimate {{ reservation_name }},</p>
                     <p>Statusul rezervării dvs. a fost actualizat la: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalii Rezervare:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Număr de Oaspeți:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Dacă aveți întrebări, vă rugăm să ne contactați.</p>
                     <p>Cu stimă,<br>Echipa Restaurantului</p>',
    ],
    'reservation_details' => 'Detalii Rezervare',
    'date' => 'Data',
    'time' => 'Ora',
    'guests' => 'Oaspeți',
    'special_requests' => 'Cereri Speciale',
    'visit_website' => 'Vizitați Site-ul Nostru',
    'view_reservation' => 'Vezi Rezervarea',
    'cancel_or_modify' => 'Dacă trebuie să anulați sau să modificați rezervarea, vă rugăm să ne contactați la :restaurant_phone sau :restaurant_email.',
    'contact_us' => 'Dacă aveți întrebări, vă rugăm să ne contactați la :restaurant_phone sau :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Confirmată',
    'cancelled' => 'Anulată',
    'note_from_restaurant' => 'Notă de la Restaurant',
    'make_new_reservation' => 'Faceți o Rezervare Nouă',
    'status_update' => [
        'approved_title' => 'Rezervare Confirmată!',
        'approved_message' => 'Vești bune! Rezervarea dvs. a fost confirmată.',
        'cancelled_title' => 'Rezervare Anulată',
        'cancelled_message' => 'Ne pare rău, dar rezervarea dvs. a fost anulată.',
        'default_title' => 'Actualizare Status Rezervare',
        'default_message' => 'Statusul rezervării dvs. a fost actualizat.',
    ],
];
