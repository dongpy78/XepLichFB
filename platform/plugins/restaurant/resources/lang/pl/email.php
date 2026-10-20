<?php

return [
    'new-reservation' => [
        'title' => 'Powiadomienie o nowej rezerwacji',
        'description' => 'E-mail wysyłany do administratora, gdy zostanie złożona nowa rezerwacja',
        'subject' => 'Nowa rezerwacja od {{ reservation_name }}',
        'header' => 'Otrzymano nową rezerwację!',
        'content' => '<p>Otrzymałeś nową rezerwację z następującymi szczegółami:</p>
                     <p><strong>Imię i nazwisko:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Godzina:</strong> {{ reservation_time }}<br>
                     <strong>Liczba gości:</strong> {{ number_of_guests }}<br>
                     <strong>Specjalne życzenia:</strong> {{ special_requests }}</p>
                     <p>Zaloguj się do panelu administratora, aby zarządzać tą rezerwacją.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potwierdzenie rezerwacji',
        'description' => 'E-mail wysyłany do klienta po złożeniu rezerwacji',
        'subject' => 'Potwierdzenie rezerwacji',
        'header' => 'Potwierdzenie rezerwacji',
        'content' => '<p>Szanowny/a {{ reservation_name }},</p>
                     <p>Dziękujemy za rezerwację! Otrzymaliśmy Twoje zgłoszenie rezerwacji z następującymi szczegółami:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Godzina:</strong> {{ reservation_time }}<br>
                     <strong>Liczba gości:</strong> {{ number_of_guests }}</p>
                     <p>Wkrótce skontaktujemy się z Tobą, aby potwierdzić rezerwację. Jeśli masz pytania, nie wahaj się z nami skontaktować.</p>
                     <p>Z poważaniem,<br>Zespół restauracji</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Aktualizacja statusu rezerwacji',
        'description' => 'E-mail wysyłany do klienta, gdy status rezerwacji zostanie zaktualizowany',
        'subject' => 'Status Twojej rezerwacji został zaktualizowany',
        'header' => 'Aktualizacja statusu rezerwacji',
        'content' => '<p>Szanowny/a {{ reservation_name }},</p>
                     <p>Status Twojej rezerwacji został zaktualizowany na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Szczegóły rezerwacji:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Godzina:</strong> {{ reservation_time }}<br>
                     <strong>Liczba gości:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Jeśli masz pytania, skontaktuj się z nami.</p>
                     <p>Z poważaniem,<br>Zespół restauracji</p>',
    ],
    'reservation_details' => 'Szczegóły rezerwacji',
    'date' => 'Data',
    'time' => 'Godzina',
    'guests' => 'Goście',
    'special_requests' => 'Specjalne życzenia',
    'visit_website' => 'Odwiedź naszą stronę',
    'view_reservation' => 'Wyświetl rezerwację',
    'cancel_or_modify' => 'Jeśli chcesz anulować lub zmodyfikować swoją rezerwację, skontaktuj się z nami pod numerem :restaurant_phone lub na adres :restaurant_email.',
    'contact_us' => 'Jeśli masz pytania, skontaktuj się z nami pod numerem :restaurant_phone lub na adres :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Potwierdzona',
    'cancelled' => 'Anulowana',
    'note_from_restaurant' => 'Notatka od restauracji',
    'make_new_reservation' => 'Złóż nową rezerwację',
    'status_update' => [
        'approved_title' => 'Rezerwacja potwierdzona!',
        'approved_message' => 'Świetna wiadomość! Twoja rezerwacja została potwierdzona.',
        'cancelled_title' => 'Rezerwacja anulowana',
        'cancelled_message' => 'Przykro nam, ale Twoja rezerwacja została anulowana.',
        'default_title' => 'Aktualizacja statusu rezerwacji',
        'default_message' => 'Status Twojej rezerwacji został zaktualizowany.',
    ],
];
