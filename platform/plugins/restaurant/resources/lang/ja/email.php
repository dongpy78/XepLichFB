<?php

return [
    'new-reservation' => [
        'title' => '新規予約通知',
        'description' => '新しい予約が送信されたときに管理者に送信されるメール',
        'subject' => '{{ reservation_name }}様からの新規予約',
        'header' => '新規予約を受け付けました!',
        'content' => '<p>以下の詳細で新しい予約を受け取りました:</p>
                     <p><strong>名前:</strong> {{ reservation_name }}<br>
                     <strong>メール:</strong> {{ reservation_email }}<br>
                     <strong>電話:</strong> {{ reservation_phone }}<br>
                     <strong>日付:</strong> {{ reservation_date }}<br>
                     <strong>時間:</strong> {{ reservation_time }}<br>
                     <strong>人数:</strong> {{ number_of_guests }}<br>
                     <strong>特別なリクエスト:</strong> {{ special_requests }}</p>
                     <p>この予約を管理するには、管理パネルにログインしてください。</p>',
    ],
    'reservation-confirmation' => [
        'title' => '予約確認',
        'description' => '予約送信後にお客様に送信されるメール',
        'subject' => 'ご予約の確認',
        'header' => '予約確認',
        'content' => '<p>{{ reservation_name }}様</p>
                     <p>ご予約ありがとうございます!以下の詳細でご予約を受け付けました:</p>
                     <p><strong>日付:</strong> {{ reservation_date }}<br>
                     <strong>時間:</strong> {{ reservation_time }}<br>
                     <strong>人数:</strong> {{ number_of_guests }}</p>
                     <p>まもなくご予約の確認のためにご連絡させていただきます。ご質問がございましたら、お気軽にお問い合わせください。</p>
                     <p>よろしくお願いいたします。<br>レストランチーム</p>',
    ],
    'reservation-status-update' => [
        'title' => '予約ステータス更新',
        'description' => '予約ステータスが更新されたときにお客様に送信されるメール',
        'subject' => 'ご予約のステータスが更新されました',
        'header' => '予約ステータス更新',
        'content' => '<p>{{ reservation_name }}様</p>
                     <p>ご予約のステータスが更新されました: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>予約詳細:</strong><br>
                     <strong>日付:</strong> {{ reservation_date }}<br>
                     <strong>時間:</strong> {{ reservation_time }}<br>
                     <strong>人数:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>ご質問がございましたら、お問い合わせください。</p>
                     <p>よろしくお願いいたします。<br>レストランチーム</p>',
    ],
    'reservation_details' => '予約詳細',
    'date' => '日付',
    'time' => '時間',
    'guests' => '人数',
    'special_requests' => '特別なリクエスト',
    'visit_website' => 'ウェブサイトを訪問',
    'view_reservation' => '予約を表示',
    'cancel_or_modify' => 'ご予約をキャンセルまたは変更する必要がある場合は、:restaurant_phoneまたは:restaurant_emailまでご連絡ください。',
    'contact_us' => 'ご質問がある場合は、:restaurant_phoneまたは:restaurant_emailまでお問い合わせください。',
    'status' => 'ステータス',
    'confirmed' => '確認済み',
    'cancelled' => 'キャンセル',
    'note_from_restaurant' => 'レストランからのメモ',
    'make_new_reservation' => '新規予約',
    'status_update' => [
        'approved_title' => '予約が確認されました!',
        'approved_message' => '素晴らしいニュースです!ご予約が確認されました。',
        'cancelled_title' => '予約がキャンセルされました',
        'cancelled_message' => '申し訳ございませんが、ご予約がキャンセルされました。',
        'default_title' => '予約ステータス更新',
        'default_message' => 'ご予約のステータスが更新されました。',
    ],
];
