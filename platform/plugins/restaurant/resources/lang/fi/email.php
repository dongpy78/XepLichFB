<?php

return [
    'new-reservation' => [
        'title' => 'Uuden varauksen ilmoitus',
        'description' => 'Sähköposti, joka lähetetään ylläpitäjälle, kun uusi varaus on tehty',
        'subject' => 'Uusi varaus käyttäjältä {{ reservation_name }}',
        'header' => 'Uusi varaus vastaanotettu!',
        'content' => '<p>Olet vastaanottanut uuden varauksen seuraavilla tiedoilla:</p>
                     <p><strong>Nimi:</strong> {{ reservation_name }}<br>
                     <strong>Sähköposti:</strong> {{ reservation_email }}<br>
                     <strong>Puhelin:</strong> {{ reservation_phone }}<br>
                     <strong>Päivämäärä:</strong> {{ reservation_date }}<br>
                     <strong>Aika:</strong> {{ reservation_time }}<br>
                     <strong>Vieraiden määrä:</strong> {{ number_of_guests }}<br>
                     <strong>Erityistoiveet:</strong> {{ special_requests }}</p>
                     <p>Kirjaudu hallintapaneeliin hallitaksesi tätä varausta.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Varauksen vahvistus',
        'description' => 'Sähköposti, joka lähetetään asiakkaalle varauksen tekemisen jälkeen',
        'subject' => 'Varauksesi vahvistus',
        'header' => 'Varauksen vahvistus',
        'content' => '<p>Hyvä {{ reservation_name }},</p>
                     <p>Kiitos varauksestasi! Olemme vastaanottaneet varauspyyntösi seuraavilla tiedoilla:</p>
                     <p><strong>Päivämäärä:</strong> {{ reservation_date }}<br>
                     <strong>Aika:</strong> {{ reservation_time }}<br>
                     <strong>Vieraiden määrä:</strong> {{ number_of_guests }}</p>
                     <p>Otamme sinuun pian yhteyttä vahvistaaksemme varauksesi. Jos sinulla on kysyttävää, älä epäröi ottaa meihin yhteyttä.</p>
                     <p>Ystävällisin terveisin,<br>Ravintolan tiimi</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Varauksen tilan päivitys',
        'description' => 'Sähköposti, joka lähetetään asiakkaalle, kun varauksen tilaa päivitetään',
        'subject' => 'Varauksesi tilaa on päivitetty',
        'header' => 'Varauksen tilan päivitys',
        'content' => '<p>Hyvä {{ reservation_name }},</p>
                     <p>Varauksesi tilaa on päivitetty: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Varauksen tiedot:</strong><br>
                     <strong>Päivämäärä:</strong> {{ reservation_date }}<br>
                     <strong>Aika:</strong> {{ reservation_time }}<br>
                     <strong>Vieraiden määrä:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Jos sinulla on kysyttävää, ota meihin yhteyttä.</p>
                     <p>Ystävällisin terveisin,<br>Ravintolan tiimi</p>',
    ],
    'reservation_details' => 'Varauksen tiedot',
    'date' => 'Päivämäärä',
    'time' => 'Aika',
    'guests' => 'Vieraat',
    'special_requests' => 'Erityistoiveet',
    'visit_website' => 'Käy verkkosivullamme',
    'view_reservation' => 'Näytä varaus',
    'cancel_or_modify' => 'Jos haluat peruuttaa tai muuttaa varaustasi, ota meihin yhteyttä numerossa :restaurant_phone tai sähköpostitse :restaurant_email.',
    'contact_us' => 'Jos sinulla on kysyttävää, ota meihin yhteyttä numerossa :restaurant_phone tai sähköpostitse :restaurant_email.',
    'status' => 'Tila',
    'confirmed' => 'Vahvistettu',
    'cancelled' => 'Peruutettu',
    'note_from_restaurant' => 'Viesti ravintolalta',
    'make_new_reservation' => 'Tee uusi varaus',
    'status_update' => [
        'approved_title' => 'Varaus vahvistettu!',
        'approved_message' => 'Hienoja uutisia! Varauksesi on vahvistettu.',
        'cancelled_title' => 'Varaus peruutettu',
        'cancelled_message' => 'Olemme pahoillamme, mutta varauksesi on peruutettu.',
        'default_title' => 'Varauksen tilan päivitys',
        'default_message' => 'Varauksesi tilaa on päivitetty.',
    ],
];
