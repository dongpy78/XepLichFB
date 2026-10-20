<?php

return [
    'new-reservation' => [
        'title' => 'Ny Reservasjon Varsel',
        'description' => 'E-post sendt til administrator når en ny reservasjon blir sendt inn',
        'subject' => 'Ny reservasjon fra {{ reservation_name }}',
        'header' => 'Ny Reservasjon Mottatt!',
        'content' => '<p>Du har mottatt en ny reservasjon med følgende detaljer:</p>
                     <p><strong>Navn:</strong> {{ reservation_name }}<br>
                     <strong>E-post:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antall Gjester:</strong> {{ number_of_guests }}<br>
                     <strong>Spesielle Ønsker:</strong> {{ special_requests }}</p>
                     <p>Vennligst logg inn på ditt administrasjonspanel for å håndtere denne reservasjonen.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Reservasjonsbekreftelse',
        'description' => 'E-post sendt til kunden etter innsending av en reservasjon',
        'subject' => 'Din reservasjonsbekreftelse',
        'header' => 'Reservasjonsbekreftelse',
        'content' => '<p>Kjære {{ reservation_name }},</p>
                     <p>Takk for din reservasjon! Vi har mottatt din bestilling med følgende detaljer:</p>
                     <p><strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antall Gjester:</strong> {{ number_of_guests }}</p>
                     <p>Vi vil kontakte deg snart for å bekrefte din reservasjon. Hvis du har spørsmål, vennligst ikke nøl med å kontakte oss.</p>
                     <p>Med vennlig hilsen,<br>Restaurant Teamet</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Reservasjonsstatus Oppdatering',
        'description' => 'E-post sendt til kunden når reservasjonsstatus oppdateres',
        'subject' => 'Din reservasjonsstatus har blitt oppdatert',
        'header' => 'Reservasjonsstatus Oppdatering',
        'content' => '<p>Kjære {{ reservation_name }},</p>
                     <p>Din reservasjonsstatus har blitt oppdatert til: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Reservasjonsdetaljer:</strong><br>
                     <strong>Dato:</strong> {{ reservation_date }}<br>
                     <strong>Tid:</strong> {{ reservation_time }}<br>
                     <strong>Antall Gjester:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Hvis du har spørsmål, vennligst kontakt oss.</p>
                     <p>Med vennlig hilsen,<br>Restaurant Teamet</p>',
    ],
    'reservation_details' => 'Reservasjonsdetaljer',
    'date' => 'Dato',
    'time' => 'Tid',
    'guests' => 'Gjester',
    'special_requests' => 'Spesielle Ønsker',
    'visit_website' => 'Besøk Vår Nettside',
    'view_reservation' => 'Vis Reservasjon',
    'cancel_or_modify' => 'Hvis du trenger å kansellere eller endre din reservasjon, vennligst kontakt oss på :restaurant_phone eller :restaurant_email.',
    'contact_us' => 'Hvis du har spørsmål, vennligst kontakt oss på :restaurant_phone eller :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Bekreftet',
    'cancelled' => 'Kansellert',
    'note_from_restaurant' => 'Merknad fra Restaurant',
    'make_new_reservation' => 'Lag Ny Reservasjon',
    'status_update' => [
        'approved_title' => 'Reservasjon Bekreftet!',
        'approved_message' => 'Gode nyheter! Din reservasjon har blitt bekreftet.',
        'cancelled_title' => 'Reservasjon Kansellert',
        'cancelled_message' => 'Vi beklager, men din reservasjon har blitt kansellert.',
        'default_title' => 'Reservasjonsstatus Oppdatering',
        'default_message' => 'Din reservasjonsstatus har blitt oppdatert.',
    ],
];
