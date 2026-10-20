#!/bin/bash
BASE_DIR="/Users/sangnguyen/workspace/restoria/platform/plugins/restaurant/resources/lang"

# Slovenian (sl)
cat > "$BASE_DIR/sl/chef.php" << 'EOF'
<?php

return [
    'name' => 'Kuharji',
    'create' => 'Nov kuhar',
    'forms' => [
        'title' => 'Naziv',
        'title_placeholder' => 'npr. Glavni kuhar, Vodja kuhinje',
        'bio' => 'Biografija',
        'bio_placeholder' => 'Napišite o kuharjevih izkušnjah, slogu kuhanja in dosežkih...',
        'experience_years' => 'Leta izkušenj',
        'experience_years_placeholder' => '15',
        'image' => 'Fotografija kuharja',
        'badge' => 'Značka/Logotip',
        'specialties' => 'Specialnosti',
        'social_links' => 'Povezave družbenih medijev',
        'social_links_help' => 'Dodajte URL-je profilov družbenih medijev za tega kuharja. Pustite prazno, če ni primerno.',
    ],
    'years_of_experience' => ':years let',
];
EOF

cat > "$BASE_DIR/sl/currency.php" << 'EOF'
<?php

return [
    'currencies' => 'Valute',
    'setting_description' => 'Seznam valut, ki se uporabljajo na spletnem mestu',
    'name' => 'Ime',
    'symbol' => 'Simbol',
    'number_of_decimals' => 'Število decimalnih mest',
    'exchange_rate' => 'Menjalni tečaj',
    'is_prefix_symbol' => 'Položaj simbola',
    'is_default' => 'Privzeto?',
    'remove' => 'Odstrani',
    'new_currency' => 'Dodaj novo valuto',
    'save_settings' => 'Shrani nastavitve',
    'before_number' => 'Pred številko',
    'after_number' => 'Za številko',
    'require_at_least_one_currency' => 'Sistem zahteva vsaj eno valuto!',
    'enable_auto_detect_visitor_currency' => 'Omogoči samodejno zaznavanje valute obiskovalca?',
    'add_new_currency' => 'Dodaj novo valuto',
    'code' => 'Koda',
    'update_currency_rates_success' => 'Menjalni tečaji uspešno posodobljeni!',
    'clear_cache_rates_success' => 'Predpomnilnik menjalnih tečajev uspešno počiščen!',
    'instruction' => 'Prosimo, kliknite gumb "Dodaj novo valuto" za dodajanje nove valute',
    'auto_detect_visitor_currency_description' => 'Samodejno zazna valuto obiskovalca na podlagi jezika brskalnika.',
    'add_space_between_price_and_currency' => 'Dodaj presledek med ceno in valuto?',
    'thousands_separator' => 'Ločilo tisočic',
    'decimal_separator' => 'Decimalno ločilo',
    'separator_comma' => 'Vejica (,)',
    'separator_period' => 'Pika (.)',
    'separator_space' => 'Presledek ( )',
    'exchange_rate_api_provider' => 'Ponudnik API menjalnega tečaja',
    'exchange_rate_none' => 'Brez',
    'exchange_rate_api_layer' => 'API Layer',
    'exchange_rate_open_exchange_rate' => 'Open Exchange Rates',
    'api_key' => 'API ključ',
    'api_key_helper' => 'Pridobite svoj API ključ iz :link',
    'open_exchange_app_id' => 'ID aplikacije Open Exchange',
    'update_currency_rates' => 'Posodobi menjalne tečaje',
    'clear_cache_rates' => 'Počisti predpomnilnik tečajev',
    'use_exchange_rate_from_api' => 'Uporabi menjalni tečaj iz API?',
    'default_currency_warning' => 'Prepričajte se, da ste nastavili privzeto valuto na seznamu valut!',
    'no_api_key' => 'API ključ ni konfiguriran',
];
EOF

cat > "$BASE_DIR/sl/email.php" << 'EOF'
<?php

return [
    'new-reservation' => [
        'title' => 'Obvestilo o novi rezervaciji',
        'description' => 'E-pošta poslana skrbniku, ko je oddana nova rezervacija',
        'subject' => 'Nova rezervacija od {{ reservation_name }}',
        'header' => 'Prejeta nova rezervacija!',
        'content' => '<p>Prejeli ste novo rezervacijo s sledečimi podrobnostmi:</p>
                     <p><strong>Ime:</strong> {{ reservation_name }}<br>
                     <strong>E-pošta:</strong> {{ reservation_email }}<br>
                     <strong>Telefon:</strong> {{ reservation_phone }}<br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}<br>
                     <strong>Posebne zahteve:</strong> {{ special_requests }}</p>
                     <p>Prosimo, prijavite se v upravljalni panel za upravljanje te rezervacije.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Potrditev rezervacije',
        'description' => 'E-pošta poslana stranki po oddaji rezervacije',
        'subject' => 'Potrditev vaše rezervacije',
        'header' => 'Potrditev rezervacije',
        'content' => '<p>Spoštovani {{ reservation_name }},</p>
                     <p>Hvala za vašo rezervacijo! Prejeli smo vašo prošnjo za rezervacijo s sledečimi podrobnostmi:</p>
                     <p><strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}</p>
                     <p>Kmalu vas bomo kontaktirali za potrditev vaše rezervacije. Če imate kakršna koli vprašanja, nas brez zadržkov kontaktirajte.</p>
                     <p>Lep pozdrav,<br>Ekipa restavracije</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Posodobitev statusa rezervacije',
        'description' => 'E-pošta poslana stranki, ko je status rezervacije posodobljen',
        'subject' => 'Status vaše rezervacije je bil posodobljen',
        'header' => 'Posodobitev statusa rezervacije',
        'content' => '<p>Spoštovani {{ reservation_name }},</p>
                     <p>Status vaše rezervacije je bil posodobljen na: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Podrobnosti rezervacije:</strong><br>
                     <strong>Datum:</strong> {{ reservation_date }}<br>
                     <strong>Čas:</strong> {{ reservation_time }}<br>
                     <strong>Število gostov:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Če imate kakršna koli vprašanja, nas prosimo kontaktirajte.</p>
                     <p>Lep pozdrav,<br>Ekipa restavracije</p>',
    ],
    'reservation_details' => 'Podrobnosti rezervacije',
    'date' => 'Datum',
    'time' => 'Čas',
    'guests' => 'Gostje',
    'special_requests' => 'Posebne zahteve',
    'visit_website' => 'Obiščite našo spletno stran',
    'view_reservation' => 'Poglej rezervacijo',
    'cancel_or_modify' => 'Če morate preklicati ali spremeniti svojo rezervacijo, nas kontaktirajte na :restaurant_phone ali :restaurant_email.',
    'contact_us' => 'Če imate kakršna koli vprašanja, nas kontaktirajte na :restaurant_phone ali :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Potrjena',
    'cancelled' => 'Preklicana',
    'note_from_restaurant' => 'Opomba restavracije',
    'make_new_reservation' => 'Naredi novo rezervacijo',
    'status_update' => [
        'approved_title' => 'Rezervacija potrjena!',
        'approved_message' => 'Odlične novice! Vaša rezervacija je bila potrjena.',
        'cancelled_title' => 'Rezervacija preklicana',
        'cancelled_message' => 'Opravičujemo se, vendar je bila vaša rezervacija preklicana.',
        'default_title' => 'Posodobitev statusa rezervacije',
        'default_message' => 'Status vaše rezervacije je bil posodobljen.',
    ],
];
EOF

cat > "$BASE_DIR/sl/enums.php" << 'EOF'
<?php

return [
    'menu-status' => [
        'published' => 'Objavljeno',
        'draft' => 'Osnutek',
        'hidden' => 'Skrito',
    ],
    'reservation-status' => [
        'pending' => 'V čakanju',
        'confirmed' => 'Potrjena',
        'cancelled' => 'Preklicana',
        'completed' => 'Zaključena',
    ],
];
EOF

cat > "$BASE_DIR/sl/menu.php" << 'EOF'
<?php

return [
    'menu_categories' => 'Kategorije menijev',
    'menu_category' => 'Kategorija menija',
    'create_menu_category' => 'Ustvari kategorijo menija',
    'menu_items' => 'Elementi menija',
    'menu_item' => 'Element menija',
    'create_menu_item' => 'Ustvari element menija',
    'menu_items_count' => 'Elementi',
    'category' => 'Kategorija',
    'price' => 'Cena',
    'sale_price' => 'Akcijska cena',
    'sale_price_help' => 'Pustite prazno, če ni popusta',
    'sale_price_must_be_less_than_price' => 'Akcijska cena mora biti nižja od redne cene',
    'is_featured' => 'Priporočeno',
    'is_vegetarian' => 'Vegetarijansko',
    'is_vegan' => 'Vegansko',
    'is_gluten_free' => 'Brez glutena',
    'calories' => 'Kalorije',
    'calories_placeholder' => 'npr. 250 kcal',
    'ingredients' => 'Sestavine',
    'ingredients_placeholder' => 'Naštejte glavne sestavine, ločene z vejico',
    'edit_this_menu_item' => 'Uredi ta element menija',
    'edit_this_menu_category' => 'Uredi to kategorijo menija',
];
EOF

cat > "$BASE_DIR/sl/reservation.php" << 'EOF'
<?php

return [
    'reservations' => 'Rezervacije',
    'reservation' => 'Rezervacija',
    'reservation_form' => 'Obrazec za rezervacijo',
    'create_reservation' => 'Ustvari rezervacijo',
    'phone' => 'Telefon',
    'phone_placeholder' => '+386 31 234 567',
    'reservation_date' => 'Datum rezervacije',
    'reservation_time' => 'Čas rezervacije',
    'reservation_datetime' => 'Datum in čas',
    'datetime_format' => ':date ob :time',
    'number_of_guests' => 'Število gostov',
    'guests' => 'Gostje',
    'special_requests' => 'Posebne zahteve',
    'special_requests_placeholder' => 'Kakršne koli posebne zahteve ali prehranske omejitve...',
    'admin_notes' => 'Skrbniške opombe',
    'admin_notes_placeholder' => 'Notranje opombe o tej rezervaciji...',
    'date_must_be_today_or_future' => 'Datum rezervacije mora biti danes ali prihodnji datum.',
    'max_guests_exceeded' => 'Največje število gostov je 20. Za večje skupine nas prosimo kontaktirajte neposredno.',
    'view_reservation' => 'Poglej rezervacijo #:id',
    'send_email_to_customer' => 'Pošlji e-pošto stranki',
    'call_customer' => 'Pokliči stranko',
    'customer_information' => 'Informacije o stranki',
    'message_information' => 'Informacije o sporočilu',
    'sender_information' => 'Informacije o pošiljatelju',
    'contact_information' => 'Kontaktne informacije',
    'reply' => 'Odgovori',
    'message_sent_success' => 'Sporočilo uspešno poslano!',
    'view_contact' => 'Poglej kontakt #:id',
    'contacts' => 'Kontakti',
    'contact' => 'Kontakt',
    'full_name' => 'Polno ime',
    'email' => 'E-pošta',
    'time' => 'Čas',
    'address' => 'Naslov',
    'subject' => 'Zadeva',
    'content' => 'Vsebina',
    'message' => 'Sporočilo',
    'sender' => 'Pošiljatelj',
    'custom_fields' => 'Polja po meri',
    'statuses' => [
        'read' => 'Prebrano',
        'unread' => 'Neprebrano',
    ],
    'information' => 'Informacije',
    'customer' => 'Stranka',
    'name' => 'Ime',
    'id' => 'ID',
    'created_at' => 'Ustvarjeno',
    'timeline' => 'Časovnica',
    'created' => 'Ustvarjeno',
    'updated' => 'Posodobljeno',
    'back' => 'Nazaj',
    'edit' => 'Uredi',
    'reservation_created_successfully' => 'Rezervacija uspešno ustvarjena!',
    'status' => 'Status',
];
EOF

cat > "$BASE_DIR/sl/restaurant.php" << 'EOF'
<?php

return [
    'menu_name' => 'Restavracija',
    'name' => 'Restavracija',
    'booking_calendar' => 'Koledar rezervacij',
    'reservation_detail' => 'Podrobnosti rezervacije',
    'calendar_tip_title' => 'Hitra navodila:',
    'calendar_tip_message' => 'Kliknite na katerikoli datum v koledarju, da hitro ustvarite novo rezervacijo za ta dan. Lahko tudi kliknete na obstoječe rezervacije za ogled ali urejanje njihovih podrobnosti.',
    'form' => [
        'name' => 'Ime',
        'email' => 'E-pošta',
        'phone' => 'Telefon',
        'reservation_date' => 'Datum rezervacije',
        'reservation_time' => 'Čas rezervacije',
        'guests' => 'Gostje',
        'status' => 'Status',
        'special_requests' => 'Posebne zahteve',
    ],
    'settings' => [
        'title' => 'Nastavitve',
        'description' => 'Konfiguriraj nastavitve in preference restavracije',
        'menu_title' => 'Nastavitve',
        'reservation_time_format' => 'Format časa rezervacije',
        '12_hours_format' => '12 ur (AM/PM)',
        '24_hours_format' => '24 ur',
        'reservation_time_format_helper' => 'Izberite format časa za izbiro časa rezervacije',
        'email' => [
            'templates' => [
                'title' => 'Predloge e-pošte restavracije',
                'description' => 'Konfiguriraj predloge e-pošte za rezervacije in obvestila restavracije',
            ],
        ],
    ],
];
EOF

echo "Slovenian (sl) completed"
