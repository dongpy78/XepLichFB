<?php

return [
    'new-reservation' => [
        'title' => '新预订通知',
        'description' => '提交新预订时发送给管理员的电子邮件',
        'subject' => '来自 {{ reservation_name }} 的新预订',
        'header' => '收到新预订！',
        'content' => '<p>您收到了一个新预订，详情如下：</p>
                     <p><strong>姓名：</strong>{{ reservation_name }}<br>
                     <strong>电子邮箱：</strong>{{ reservation_email }}<br>
                     <strong>电话：</strong>{{ reservation_phone }}<br>
                     <strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>时间：</strong>{{ reservation_time }}<br>
                     <strong>客人数量：</strong>{{ number_of_guests }}<br>
                     <strong>特殊要求：</strong>{{ special_requests }}</p>
                     <p>请登录管理面板以管理此预订。</p>',
    ],
    'reservation-confirmation' => [
        'title' => '预订确认',
        'description' => '提交预订后发送给客户的电子邮件',
        'subject' => '您的预订确认',
        'header' => '预订确认',
        'content' => '<p>尊敬的 {{ reservation_name }}，</p>
                     <p>感谢您的预订！我们已收到您的预订请求，详情如下：</p>
                     <p><strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>时间：</strong>{{ reservation_time }}<br>
                     <strong>客人数量：</strong>{{ number_of_guests }}</p>
                     <p>我们将很快与您联系以确认您的预订。如果您有任何问题，请随时联系我们。</p>
                     <p>此致敬礼，<br>餐厅团队</p>',
    ],
    'reservation-status-update' => [
        'title' => '预订状态更新',
        'description' => '预订状态更新时发送给客户的电子邮件',
        'subject' => '您的预订状态已更新',
        'header' => '预订状态更新',
        'content' => '<p>尊敬的 {{ reservation_name }}，</p>
                     <p>您的预订状态已更新为：<strong>{{ reservation_status }}</strong></p>
                     <p><strong>预订详情：</strong><br>
                     <strong>日期：</strong>{{ reservation_date }}<br>
                     <strong>时间：</strong>{{ reservation_time }}<br>
                     <strong>客人数量：</strong>{{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>如有任何问题，请联系我们。</p>
                     <p>此致敬礼，<br>餐厅团队</p>',
    ],
    'reservation_details' => '预订详情',
    'date' => '日期',
    'time' => '时间',
    'guests' => '客人',
    'special_requests' => '特殊要求',
    'visit_website' => '访问我们的网站',
    'view_reservation' => '查看预订',
    'cancel_or_modify' => '如需取消或修改您的预订，请通过 :restaurant_phone 或 :restaurant_email 联系我们。',
    'contact_us' => '如有任何问题，请通过 :restaurant_phone 或 :restaurant_email 联系我们。',
    'status' => '状态',
    'confirmed' => '已确认',
    'cancelled' => '已取消',
    'note_from_restaurant' => '餐厅备注',
    'make_new_reservation' => '新建预订',
    'status_update' => [
        'approved_title' => '预订已确认！',
        'approved_message' => '好消息！您的预订已确认。',
        'cancelled_title' => '预订已取消',
        'cancelled_message' => '很抱歉，您的预订已被取消。',
        'default_title' => '预订状态更新',
        'default_message' => '您的预订状态已更新。',
    ],
];
