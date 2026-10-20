#!/bin/bash
BASE_DIR="/Users/sangnguyen/workspace/restoria/platform/plugins/restaurant/resources/lang"

# Brazilian Portuguese (pt_BR) - Note: using Brazilian terminology and spellings
cat > "$BASE_DIR/pt_BR/chef.php" << 'EOF'
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
        'image' => 'Foto do Chef',
        'badge' => 'Distintivo/Logo',
        'specialties' => 'Especialidades',
        'social_links' => 'Links de Redes Sociais',
        'social_links_help' => 'Adicione URLs de perfis de redes sociais para este chef. Deixe em branco se não aplicável.',
    ],
    'years_of_experience' => ':years anos',
];
EOF

cat > "$BASE_DIR/pt_BR/currency.php" << 'EOF'
<?php

return [
    'currencies' => 'Moedas',
    'setting_description' => 'Lista de moedas utilizadas no site',
    'name' => 'Nome',
    'symbol' => 'Símbolo',
    'number_of_decimals' => 'Número de casas decimais',
    'exchange_rate' => 'Taxa de câmbio',
    'is_prefix_symbol' => 'Posição do símbolo',
    'is_default' => 'É padrão?',
    'remove' => 'Remover',
    'new_currency' => 'Adicionar nova moeda',
    'save_settings' => 'Salvar configurações',
    'before_number' => 'Antes do número',
    'after_number' => 'Depois do número',
    'require_at_least_one_currency' => 'O sistema requer pelo menos uma moeda!',
    'enable_auto_detect_visitor_currency' => 'Ativar detecção automática da moeda do visitante?',
    'add_new_currency' => 'Adicionar nova moeda',
    'code' => 'Código',
    'update_currency_rates_success' => 'Taxas de câmbio atualizadas com sucesso!',
    'clear_cache_rates_success' => 'Cache de taxas de câmbio limpo com sucesso!',
    'instruction' => 'Por favor, clique no botão "Adicionar nova moeda" para adicionar uma nova moeda',
    'auto_detect_visitor_currency_description' => 'Detecta automaticamente a moeda do visitante com base no idioma do navegador.',
    'add_space_between_price_and_currency' => 'Adicionar um espaço entre o preço e a moeda?',
    'thousands_separator' => 'Separador de milhares',
    'decimal_separator' => 'Separador decimal',
    'separator_comma' => 'Vírgula (,)',
    'separator_period' => 'Ponto (.)',
    'separator_space' => 'Espaço ( )',
    'exchange_rate_api_provider' => 'Provedor de API de Taxas de Câmbio',
    'exchange_rate_none' => 'Nenhum',
    'exchange_rate_api_layer' => 'API Layer',
    'exchange_rate_open_exchange_rate' => 'Open Exchange Rates',
    'api_key' => 'Chave API',
    'api_key_helper' => 'Obtenha sua chave API em :link',
    'open_exchange_app_id' => 'ID do Aplicativo Open Exchange',
    'update_currency_rates' => 'Atualizar Taxas de Câmbio',
    'clear_cache_rates' => 'Limpar Cache de Taxas',
    'use_exchange_rate_from_api' => 'Usar taxa de câmbio da API?',
    'default_currency_warning' => 'Por favor, certifique-se de que definiu a moeda padrão na lista de moedas!',
    'no_api_key' => 'Nenhuma chave API configurada',
];
EOF

cat > "$BASE_DIR/pt_BR/email.php" << 'EOF'
<?php

return [
    'new-reservation' => [
        'title' => 'Notificação de Nova Reserva',
        'description' => 'E-mail enviado ao administrador quando uma nova reserva é submetida',
        'subject' => 'Nova reserva de {{ reservation_name }}',
        'header' => 'Nova Reserva Recebida!',
        'content' => '<p>Você recebeu uma nova reserva com os seguintes detalhes:</p>
                     <p><strong>Nome:</strong> {{ reservation_name }}<br>
                     <strong>E-mail:</strong> {{ reservation_email }}<br>
                     <strong>Telefone:</strong> {{ reservation_phone }}<br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}<br>
                     <strong>Pedidos Especiais:</strong> {{ special_requests }}</p>
                     <p>Por favor, faça login no painel de administração para gerenciar esta reserva.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Confirmação de Reserva',
        'description' => 'E-mail enviado ao cliente após submeter uma reserva',
        'subject' => 'Confirmação da sua reserva',
        'header' => 'Confirmação de Reserva',
        'content' => '<p>Prezado(a) {{ reservation_name }},</p>
                     <p>Obrigado pela sua reserva! Recebemos o seu pedido de reserva com os seguintes detalhes:</p>
                     <p><strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>Entraremos em contato em breve para confirmar sua reserva. Se tiver alguma dúvida, não hesite em nos contactar.</p>
                     <p>Atenciosamente,<br>A Equipe do Restaurante</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Atualização do Status da Reserva',
        'description' => 'E-mail enviado ao cliente quando o status da reserva é atualizado',
        'subject' => 'O status da sua reserva foi atualizado',
        'header' => 'Atualização do Status da Reserva',
        'content' => '<p>Prezado(a) {{ reservation_name }},</p>
                     <p>O status da sua reserva foi atualizado para: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Detalhes da Reserva:</strong><br>
                     <strong>Data:</strong> {{ reservation_date }}<br>
                     <strong>Horário:</strong> {{ reservation_time }}<br>
                     <strong>Número de Convidados:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Se tiver alguma dúvida, por favor entre em contato conosco.</p>
                     <p>Atenciosamente,<br>A Equipe do Restaurante</p>',
    ],
    'reservation_details' => 'Detalhes da Reserva',
    'date' => 'Data',
    'time' => 'Horário',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'visit_website' => 'Visite Nosso Site',
    'view_reservation' => 'Ver Reserva',
    'cancel_or_modify' => 'Se precisar cancelar ou modificar sua reserva, entre em contato conosco através do :restaurant_phone ou :restaurant_email.',
    'contact_us' => 'Se tiver alguma dúvida, entre em contato conosco através do :restaurant_phone ou :restaurant_email.',
    'status' => 'Status',
    'confirmed' => 'Confirmada',
    'cancelled' => 'Cancelada',
    'note_from_restaurant' => 'Nota do Restaurante',
    'make_new_reservation' => 'Fazer Nova Reserva',
    'status_update' => [
        'approved_title' => 'Reserva Confirmada!',
        'approved_message' => 'Ótimas notícias! Sua reserva foi confirmada.',
        'cancelled_title' => 'Reserva Cancelada',
        'cancelled_message' => 'Lamentamos, mas sua reserva foi cancelada.',
        'default_title' => 'Atualização do Status da Reserva',
        'default_message' => 'O status da sua reserva foi atualizado.',
    ],
];
EOF

cat > "$BASE_DIR/pt_BR/enums.php" << 'EOF'
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

cat > "$BASE_DIR/pt_BR/menu.php" << 'EOF'
<?php

return [
    'menu_categories' => 'Categorias do Cardápio',
    'menu_category' => 'Categoria do Cardápio',
    'create_menu_category' => 'Criar Categoria do Cardápio',
    'menu_items' => 'Itens do Cardápio',
    'menu_item' => 'Item do Cardápio',
    'create_menu_item' => 'Criar Item do Cardápio',
    'menu_items_count' => 'Itens',
    'category' => 'Categoria',
    'price' => 'Preço',
    'sale_price' => 'Preço Promocional',
    'sale_price_help' => 'Deixe vazio se não houver desconto',
    'sale_price_must_be_less_than_price' => 'O preço promocional deve ser menor que o preço normal',
    'is_featured' => 'Destaque',
    'is_vegetarian' => 'Vegetariano',
    'is_vegan' => 'Vegano',
    'is_gluten_free' => 'Sem Glúten',
    'calories' => 'Calorias',
    'calories_placeholder' => 'ex. 250 kcal',
    'ingredients' => 'Ingredientes',
    'ingredients_placeholder' => 'Liste os principais ingredientes separados por vírgulas',
    'edit_this_menu_item' => 'Editar este item do cardápio',
    'edit_this_menu_category' => 'Editar esta categoria do cardápio',
];
EOF

cat > "$BASE_DIR/pt_BR/reservation.php" << 'EOF'
<?php

return [
    'reservations' => 'Reservas',
    'reservation' => 'Reserva',
    'reservation_form' => 'Formulário de Reserva',
    'create_reservation' => 'Criar Reserva',
    'phone' => 'Telefone',
    'phone_placeholder' => '+55 11 98765-4321',
    'reservation_date' => 'Data da Reserva',
    'reservation_time' => 'Horário da Reserva',
    'reservation_datetime' => 'Data e Horário',
    'datetime_format' => ':date às :time',
    'number_of_guests' => 'Número de Convidados',
    'guests' => 'Convidados',
    'special_requests' => 'Pedidos Especiais',
    'special_requests_placeholder' => 'Quaisquer pedidos especiais ou restrições alimentares...',
    'admin_notes' => 'Notas do Administrador',
    'admin_notes_placeholder' => 'Notas internas sobre esta reserva...',
    'date_must_be_today_or_future' => 'A data da reserva deve ser hoje ou uma data futura.',
    'max_guests_exceeded' => 'O número máximo de convidados é 20. Para grupos maiores, entre em contato conosco diretamente.',
    'view_reservation' => 'Ver Reserva #:id',
    'send_email_to_customer' => 'Enviar E-mail ao Cliente',
    'call_customer' => 'Ligar para o Cliente',
    'customer_information' => 'Informações do Cliente',
    'message_information' => 'Informações da Mensagem',
    'sender_information' => 'Informações do Remetente',
    'contact_information' => 'Informações de Contato',
    'reply' => 'Responder',
    'message_sent_success' => 'Mensagem enviada com sucesso!',
    'view_contact' => 'Ver Contato #:id',
    'contacts' => 'Contatos',
    'contact' => 'Contato',
    'full_name' => 'Nome Completo',
    'email' => 'E-mail',
    'time' => 'Horário',
    'address' => 'Endereço',
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
    'timeline' => 'Linha do Tempo',
    'created' => 'Criada',
    'updated' => 'Atualizada',
    'back' => 'Voltar',
    'edit' => 'Editar',
    'reservation_created_successfully' => 'Reserva criada com sucesso!',
    'status' => 'Status',
];
EOF

cat > "$BASE_DIR/pt_BR/restaurant.php" << 'EOF'
<?php

return [
    'menu_name' => 'Restaurante',
    'name' => 'Restaurante',
    'booking_calendar' => 'Calendário de Reservas',
    'reservation_detail' => 'Detalhes da Reserva',
    'calendar_tip_title' => 'Dica Rápida:',
    'calendar_tip_message' => 'Clique em qualquer data no calendário para criar rapidamente uma nova reserva para esse dia. Você também pode clicar em reservas existentes para ver ou editar seus detalhes.',
    'form' => [
        'name' => 'Nome',
        'email' => 'E-mail',
        'phone' => 'Telefone',
        'reservation_date' => 'Data da Reserva',
        'reservation_time' => 'Horário da Reserva',
        'guests' => 'Convidados',
        'status' => 'Status',
        'special_requests' => 'Pedidos Especiais',
    ],
    'settings' => [
        'title' => 'Configurações',
        'description' => 'Configurar definições e preferências do restaurante',
        'menu_title' => 'Configurações',
        'reservation_time_format' => 'Formato do Horário da Reserva',
        '12_hours_format' => '12 Horas (AM/PM)',
        '24_hours_format' => '24 Horas',
        'reservation_time_format_helper' => 'Escolha o formato do horário para seleção do horário da reserva',
        'email' => [
            'templates' => [
                'title' => 'Modelos de E-mail do Restaurante',
                'description' => 'Configurar modelos de e-mail para reservas e notificações do restaurante',
            ],
        ],
    ],
];
EOF

echo "Brazilian Portuguese (pt_BR) completed"
