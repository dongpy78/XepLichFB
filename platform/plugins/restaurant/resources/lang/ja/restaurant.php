<?php

return [
    'menu_name' => 'レストラン',
    'name' => 'レストラン',
    'booking_calendar' => '予約カレンダー',
    'reservation_detail' => '予約詳細',
    'calendar_tip_title' => 'クイックヒント:',
    'calendar_tip_message' => 'カレンダーの任意の日付をクリックすると、その日の新しい予約をすばやく作成できます。既存の予約をクリックして、詳細を表示または編集することもできます。',
    'form' => [
        'name' => '名前',
        'email' => 'メール',
        'phone' => '電話番号',
        'reservation_date' => '予約日',
        'reservation_time' => '予約時間',
        'guests' => '人数',
        'status' => 'ステータス',
        'special_requests' => '特別なリクエスト',
    ],
    'settings' => [
        'title' => '設定',
        'description' => 'レストランの設定と環境設定を構成',
        'menu_title' => '設定',
        'reservation_time_format' => '予約時間形式',
        '12_hours_format' => '12時間制 (AM/PM)',
        '24_hours_format' => '24時間制',
        'reservation_time_format_helper' => '予約時間選択の時間形式を選択してください',
        'email' => [
            'templates' => [
                'title' => 'レストランメールテンプレート',
                'description' => 'レストランの予約と通知のメールテンプレートを設定',
            ],
        ],
    ],

    'not_available' => '利用不可',
    'delete_confirm_title' => '削除の確認',
    'delete_confirm_message' => 'この予約を削除してもよろしいですか？この操作は元に戻せません。',
    'delete' => '削除',
];
