#!/bin/bash

# Portuguese European (pt)
cat > "pt/chef.php" << 'EOF'
<?php

return [
    'name' => 'Chefs',
    'create' => 'Novo chef',
    'forms' => [
        'title' => 'Título',
        'title_placeholder' => 'ex. Chef Executivo, Chef de Cozinha',
        'bio' => 'Biografia',
        'bio_placeholder' => 'Escreva sobre a experiência, estilo culinário e conquistas do chef...',
        'experience_years' => 'Anos de Experiência',
        'experience_years_placeholder' => '15',
        'image' => 'Fotografia do Chef',
        'badge' => 'Distintivo/Logotipo',
        'specialties' => 'Especialidades',
        'social_links' => 'Links de Redes Sociais',
        'social_links_help' => 'Adicione URLs de perfis de redes sociais para este chef. Deixe em branco se não aplicável.',
    ],
    'years_of_experience' => ':years anos',
];
EOF

cat > "pt/currency.php" << 'EOF'
<?php

return [
    'currencies' => 'Moedas',
    'setting_description' => 'Lista de moedas utilizadas no website',
    'name' => 'Nome',
    'symbol' => 'Símbolo',
    'number_of_decimals' => 'Número de casas decimais',
    'exchange_rate' => 'Taxa de câmbio',
    'is_prefix_symbol' => 'Posição do símbolo',
    'is_default' => 'É padrão?',
    'remove' => 'Remover',
    'new_currency' => 'Adicionar nova moeda',
    'save_settings' => 'Guardar definições',
    'before_number' => 'Antes do número',
    'after_number' => 'Depois do número',
    'require_at_least_one_currency' => 'O sistema requer pelo menos uma moeda!',
    'enable_auto_detect_visitor_currency' => 'Ativar deteção automática da moeda do visitante?',
    'add_new_currency' => 'Adicionar nova moeda',
    'code' => 'Código',
    'update_currency_rates_success' => 'Taxas de câmbio atualizadas com sucesso!',
    'clear_cache_rates_success' => 'Cache de taxas de câmbio limpo com sucesso!',
    'instruction' => 'Por favor, clique no botão "Adicionar nova moeda" para adicionar uma nova moeda',
    'auto_detect_visitor_currency_description' => 'Deteta automaticamente a moeda do visitante com base no idioma do navegador.',
    'add_space_between_price_and_currency' => 'Adicionar um espaço entre o preço e a moeda?',
    'thousands_separator' => 'Separador de milhares',
    'decimal_separator' => 'Separador decimal',
    'separator_comma' => 'Vírgula (,)',
    'separator_period' => 'Ponto (.)',
    'separator_space' => 'Espaço ( )',
    'exchange_rate_api_provider' => 'Fornecedor de API de Taxas de Câmbio',
    'exchange_rate_none' => 'Nenhum',
    'exchange_rate_api_layer' => 'API Layer',
    'exchange_rate_open_exchange_rate' => 'Open Exchange Rates',
    'api_key' => 'Chave API',
    'api_key_helper' => 'Obtenha a sua chave API em :link',
    'open_exchange_app_id' => 'ID da Aplicação Open Exchange',
    'update_currency_rates' => 'Atualizar Taxas de Câmbio',
    'clear_cache_rates' => 'Limpar Cache de Taxas',
    'use_exchange_rate_from_api' => 'Usar taxa de câmbio da API?',
    'default_currency_warning' => 'Por favor, certifique-se de que definiu a moeda padrão na lista de moedas!',
    'no_api_key' => 'Nenhuma chave API configurada',
];
EOF

cat > "pt/email.php" << 'EOF'
<?php

return [
    'new-reservation' => [
        'title' => 'Notificação de Nova Reserva',
        'description' => 'E-mail enviado ao administrador quando uma nova reserva é submetida',
        'subject' => 'Nova reserva de {{ reservation_name }}',
        'header' => 'Nova Reserva Recebida!',
        'content' => '<p>Recebeu uma nova reserva com os seguintes detalhes:</p>
                     <p><strong>Nome:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefone:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}<br>
                     <strong>Pedidos Especiais:</strong> {{ special_requests }}</p>
                     <p>Por favor, inicie sessão no painel de administração para gerir esta reserva.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmação de Reserva',
        'description' => 'E-mail enviado ao cliente após submeter uma reserva',
        'subject' => 'Confirmação da sua reserva',
        'header' => 'Confirmação de Reserva',
        'content' => '<p>Caro(a) {{ reservation_name }},</p>
                     <p>Obrigado pela sua reserva! Recebemos o seu pedido de reserva com os seguintes detalhes:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>Entraremos em contacto em breve para confirmar a sua reserva. Se tiver alguma questão, não hesite em contactar-nos.</p>
                     <p>Cumprimentos,<br>A Equipa do Restaurante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Atualização do Estado da Reserva',
        'description' => 'E-mail enviado ao cliente quando o estado da reserva é atualizado',
        'subject' => 'O estado da sua reserva foi atualizado',
        'header' => 'Atualização do Estado da Reserva',
        'content' => '<p>Caro(a) {{ reservation_name }},</p>
                     <p>O estado da sua reserva foi atualizado para: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalhes da Reserva:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Hora:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Se tiver alguma questão, por favor contacte-nos.</p>
                     <p>Cumprimentos,<br>A Equipa do Restaurante</p>',
    ],
    'reservation_details' => 'Detalhes da Reserva',
    'date' => 'Data',
    'time' => 'Hora',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'visit_website' => 'Visite o Nosso Website',
    'view_reservation' => 'Ver Reserva',
    'cancel_or_modify' => 'Se precisar de cancelar ou modificar a sua reserva, por favor contacte-nos através do :restaurant_phone ou :restaurant_email.',
    'contact_us' => 'Se tiver alguma questão, contacte-nos através do :restaurant_phone ou :restaurant_email.',
    'status' => 'Estado',
    'confirmed' => 'Confirmada',
    'cancelled' => 'Cancelada',
    'note_from_restaurant' => 'Nota do Restaurante',
    'make_new_reservation' => 'Fazer Nova Reserva',
    'status_update' => [
        'approved_title' => 'Reserva Confirmada!',
        'approved_message' => 'Ótimas notícias! A sua reserva foi confirmada.',
        'cancelled_title' => 'Reserva Cancelada',
        'cancelled_message' => 'Lamentamos, mas a sua reserva foi cancelada.',
        'default_title' => 'Atualização do Estado da Reserva',
        'default_message' => 'O estado da sua reserva foi atualizado.',
    ],
];
EOF

cat > "pt/enums.php" << 'EOF'
<?php

return [
    'menu-status' => [
        'published' => 'Publicado',
        'draft' => 'Rascunho',
        'hidden' => 'Oculto',
    ],
    'reservation-status' => [
        'pending' => 'Pendente',
        'confirmed' => 'Confirmada',
        'cancelled' => 'Cancelada',
        'completed' => 'Concluída',
    ],
];
EOF

cat > "pt/menu.php" << 'EOF'
<?php

return [
    'menu_categories' => 'Categorias do Menu',
    'menu_category' => 'Categoria do Menu',
    'create_menu_category' => 'Criar Categoria do Menu',
    'menu_items' => 'Itens do Menu',
    'menu_item' => 'Item do Menu',
    'create_menu_item' => 'Criar Item do Menu',
    'menu_items_count' => 'Itens',
    'category' => 'Categoria',
    'price' => 'Preço',
    'sale_price' => 'Preço de Promoção',
    'sale_price_help' => 'Deixe vazio se não houver desconto',
    'sale_price_must_be_less_than_price' => 'O preço de promoção deve ser inferior ao preço normal',
    'is_featured' => 'Destaque',
    'is_vegetarian' => 'Vegetariano',
    'is_vegan' => 'Vegan',
    'is_gluten_free' => 'Sem Glúten',
    'calories' => 'Calorias',
    'calories_placeholder' => 'ex. 250 kcal',
    'ingredients' => 'Ingredientes',
    'ingredients_placeholder' => 'Liste os principais ingredientes separados por vírgulas',
    'edit_this_menu_item' => 'Editar este item do menu',
    'edit_this_menu_category' => 'Editar esta categoria do menu',
];
EOF

cat > "pt/reservation.php" << 'EOF'
<?php

return [
    'reservations' => 'Reservas',
    'reservation' => 'Reserva',
    'reservation_form' => 'Formulário de Reserva',
    'create_reservation' => 'Criar Reserva',
    'phone' => 'Telefone',
    'phone_placeholder' => '+351 912 345 678',
    'reservation_date' => 'Data da Reserva',
    'reservation_time' => 'Hora da Reserva',
    'reservation_datetime' => 'Data e Hora',
    'datetime_format' => ':date às :time',
    'number_of_guests' => 'Número de Convidados',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'special_requests_placeholder' => 'Quaisquer pedidos especiais ou restrições alimentares...',
    'admin_notes' => 'Notas do Administrador',
    'admin_notes_placeholder' => 'Notas internas sobre esta reserva...',
    'date_must_be_today_or_future' => 'A data da reserva deve ser hoje ou uma data futura.',
    'max_guests_exceeded' => 'O número máximo de convidados é 20. Para grupos maiores, contacte-nos diretamente.',
    'view_reservation' => 'Ver Reserva #:id',
    'send_email_to_customer' => 'Enviar E-mail ao Cliente',
    'call_customer' => 'Ligar ao Cliente',
    'customer_information' => 'Informações do Cliente',
    'message_information' => 'Informações da Mensagem',
    'sender_information' => 'Informações do Remetente',
    'contact_information' => 'Informações de Contacto',
    'reply' => 'Responder',
    'message_sent_success' => 'Mensagem enviada com sucesso!',
    'view_contact' => 'Ver Contacto #:id',
    'contacts' => 'Contactos',
    'contact' => 'Contacto',
    'full_name' => 'Nome Completo',
    'email' => 'E-mail',
    'time' => 'Hora',
    'address' => 'Morada',
    'subject' => 'Assunto',
    'content' => 'Conteúdo',
    'message' => 'Mensagem',
    'sender' => 'Remetente',
    'custom_fields' => 'Campos Personalizados',
    'statuses' => [
        'read' => 'Lida',
        'unread' => 'Não Lida',
    ],
    'information' => 'Informação',
    'customer' => 'Cliente',
    'name' => 'Nome',
    'id' => 'ID',
    'created_at' => 'Criada Em',
    'timeline' => 'Cronologia',
    'created' => 'Criada',
    'updated' => 'Atualizada',
    'back' => 'Voltar',
    'edit' => 'Editar',
    'reservation_created_successfully' => 'Reserva criada com sucesso!',
    'status' => 'Estado',
];
EOF

cat > "pt/restaurant.php" << 'EOF'
<?php

return [
    'menu_name' => 'Restaurante',
    'name' => 'Restaurante',
    'booking_calendar' => 'Calendário de Reservas',
    'reservation_detail' => 'Detalhes da Reserva',
    'calendar_tip_title' => 'Dica Rápida:',
    'calendar_tip_message' => 'Clique em qualquer data no calendário para criar rapidamente uma nova reserva para esse dia. Também pode clicar em reservas existentes para ver ou editar os seus detalhes.',
    'form' => [
        'name' => 'Nome',
        'email' => 'E-mail',
        'phone' => 'Telefone',
        'reservation_date' => 'Data da Reserva',
        'reservation_time' => 'Hora da Reserva',
        'guests' => 'Convidados',
        'status' => 'Estado',
        'special_requests' => 'Pedidos Especiais',
    ],
    'settings' => [
        'title' => 'Definições',
        'description' => 'Configurar definições e preferências do restaurante',
        'menu_title' => 'Definições',
        'reservation_time_format' => 'Formato da Hora da Reserva',
        '12_hours_format' => '12 Horas (AM/PM)',
        '24_hours_format' => '24 Horas',
        'reservation_time_format_helper' => 'Escolha o formato da hora para seleção da hora da reserva',
        'email' => [
            'templates' => [
                'title' => 'Modelos de E-mail do Restaurante',
                'description' => 'Configurar modelos de e-mail para reservas e notificações do restaurante',
            ],
        ],
    ],
];
EOF

echo "Portuguese European (pt) completed"
