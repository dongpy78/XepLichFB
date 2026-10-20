<?php

return [
    'new-reservation' => [
        'title' => '新預訂通知',
        'description' => '提交新預訂時傳送給管理員的電郵',
        'subject' => '來自 {{ reservation_name }} 的新預訂',
        'header' => '收到新預訂！',
        'content' => '<p>您收到了一個新預訂，詳情如下：</p>
                     <p><strong>姓名：</strong>{{ reservation_name }}<br>
                     <strong>電郵：</strong>{{ reservation_email }}<br>
                     <strong>電話：</strong>{{ reservation_phone }}<br>
                     <strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>時間：</strong>{{ reservation_time }}<br>
                     <strong>客人數量：</strong>{{ number_of_guests }}<br>
                     <strong>特殊要求：</strong>{{ special_requests }}</p>
                     <p>請登入管理介面以管理此預訂。</p>',
    ],
    'reservation-confirmation' => [
        'title' => '預訂確認',
        'description' => '提交預訂後傳送給客戶的電郵',
        'subject' => '您的預訂確認',
        'header' => '預訂確認',
        'content' => '<p>尊敬的 {{ reservation_name }}，</p>
                     <p>感謝您的預訂！我們已收到您的預訂要求，詳情如下：</p>
                     <p><strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>時間：</strong>{{ reservation_time }}<br>
                     <strong>客人數量：</strong>{{ number_of_guests }}</p>
                     <p>我們將盡快與您聯絡以確認您的預訂。如果您有任何問題，請隨時聯絡我們。</p>
                     <p>此致敬禮，<br>餐廳團隊</p>',
    ],
    'reservation-status-update' => [
        'title' => '預訂狀態更新',
        'description' => '預訂狀態更新時傳送給客戶的電郵',
        'subject' => '您的預訂狀態已更新',
        'header' => '預訂狀態更新',
        'content' => '<p>尊敬的 {{ reservation_name }}，</p>
                     <p>您的預訂狀態已更新為：<strong>{{ reservation_status }}</strong></p>
                     <p><strong>預訂詳情：</strong><br>
                     <strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>時間：</strong>{{ reservation_time }}<br>
                     <strong>客人數量：</strong>{{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>如有任何問題，請聯絡我們。</p>
                     <p>此致敬禮，<br>餐廳團隊</p>',
    ],
    'reservation_details' => '預訂詳情',
    'date' => '日期',
    'time' => '時間',
    'guests' => '客人',
    'special_requests' => '特殊要求',
    'visit_website' => '瀏覽我們的網站',
    'view_reservation' => '查看預訂',
    'cancel_or_modify' => '如需取消或修改您的預訂，請透過 :restaurant_phone 或 :restaurant_email 聯絡我們。',
    'contact_us' => '如有任何問題，請透過 :restaurant_phone 或 :restaurant_email 聯絡我們。',
    'status' => '狀態',
    'confirmed' => '已確認',
    'cancelled' => '已取消',
    'note_from_restaurant' => '餐廳備註',
    'make_new_reservation' => '建立新預訂',
    'status_update' => [
        'approved_title' => '預訂已確認！',
        'approved_message' => '好消息！您的預訂已確認。',
        'cancelled_title' => '預訂已取消',
        'cancelled_message' => '很抱歉，您的預訂已被取消。',
        'default_title' => '預訂狀態更新',
        'default_message' => '您的預訂狀態已更新。',
    ],
];
