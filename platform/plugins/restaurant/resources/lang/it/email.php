<?php

return [
    'new-reservation' => [
        'title' => 'Notifica nuova prenotazione',
        'description' => 'Email inviata all\'amministratore quando viene inviata una nuova prenotazione',
        'subject' => 'Nuova prenotazione da {{ reservation_name }}',
        'header' => 'Nuova prenotazione ricevuta!',
        'content' => '<p>Hai ricevuto una nuova prenotazione con i seguenti dettagli:</p>
                     <p><strong>Nome:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Telefono:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Numero di ospiti:</strong> {{ number_of_guests }}<br>
                     <strong>Richieste speciali:</strong> {{ special_requests }}</p>
                     <p>Accedi al pannello amministrativo per gestire questa prenotazione.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Conferma prenotazione',
        'description' => 'Email inviata al cliente dopo aver inviato una prenotazione',
        'subject' => 'Conferma della tua prenotazione',
        'header' => 'Conferma prenotazione',
        'content' => '<p>Gentile {{ reservation_name }},</p>
                     <p>Grazie per la tua prenotazione! Abbiamo ricevuto la tua richiesta con i seguenti dettagli:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Numero di ospiti:</strong> {{ number_of_guests }}</p>
                     <p>Ti contatteremo presto per confermare la tua prenotazione. Per qualsiasi domanda, non esitare a contattarci.</p>
                     <p>Cordiali saluti,<br>Il team del ristorante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Aggiornamento stato prenotazione',
        'description' => 'Email inviata al cliente quando lo stato della prenotazione viene aggiornato',
        'subject' => 'Lo stato della tua prenotazione è stato aggiornato',
        'header' => 'Aggiornamento stato prenotazione',
        'content' => '<p>Gentile {{ reservation_name }},</p>
                     <p>Lo stato della tua prenotazione è stato aggiornato a: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Dettagli prenotazione:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Ora:</strong> {{ reservation_time }}<br>
                     <strong>Numero di ospiti:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Per qualsiasi domanda, contattaci.</p>
                     <p>Cordiali saluti,<br>Il team del ristorante</p>',
    ],
    'reservation_details' => 'Dettagli prenotazione',
    'date' => 'Data',
    'time' => 'Ora',
    'guests' => 'Ospiti',
    'special_requests' => 'Richieste speciali',
    'visit_website' => 'Visita il nostro sito',
    'view_reservation' => 'Visualizza prenotazione',
    'cancel_or_modify' => 'Se hai bisogno di annullare o modificare la tua prenotazione, contattaci al :restaurant_phone o :restaurant_email.',
    'contact_us' => 'Per qualsiasi domanda, contattaci al :restaurant_phone o :restaurant_email.',
    'status' => 'Stato',
    'confirmed' => 'Confermato',
    'cancelled' => 'Annullato',
    'note_from_restaurant' => 'Nota dal ristorante',
    'make_new_reservation' => 'Nuova prenotazione',
    'status_update' => [
        'approved_title' => 'Prenotazione confermata!',
        'approved_message' => 'Ottime notizie! La tua prenotazione è stata confermata.',
        'cancelled_title' => 'Prenotazione annullata',
        'cancelled_message' => 'Siamo spiacenti, ma la tua prenotazione è stata annullata.',
        'default_title' => 'Aggiornamento stato prenotazione',
        'default_message' => 'Lo stato della tua prenotazione è stato aggiornato.',
    ],
];
