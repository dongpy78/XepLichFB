<?php

return [
    'new-reservation' => [
        'title' => 'Thông báo đặt bàn mới',
        'description' => 'Email gửi cho quản trị viên khi có đặt bàn mới',
        'subject' => 'Đặt bàn mới từ {{ reservation_name }}',
        'header' => 'Đã nhận được đặt bàn mới!',
        'content' => '<p>Bạn đã nhận được một đặt bàn mới với thông tin như sau:</p>
                     <p><strong>Tên:</strong> {{ reservation_name }}<br>
                     <strong>Email:</strong> {{ reservation_email }}<br>
                     <strong>Điện thoại:</strong> {{ reservation_phone }}<br>
                     <strong>Ngày:</strong> {{ reservation_date }}<br>
                     <strong>Giờ:</strong> {{ reservation_time }}<br>
                     <strong>Số lượng khách:</strong> {{ number_of_guests }}<br>
                     <strong>Yêu cầu đặc biệt:</strong> {{ special_requests }}</p>
                     <p>Vui lòng đăng nhập vào bảng quản trị để xử lý đặt bàn này.</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'Xác nhận đặt bàn',
        'description' => 'Email gửi cho khách hàng sau khi gửi đặt bàn',
        'subject' => 'Xác nhận đặt bàn của bạn',
        'header' => 'Xác nhận đặt bàn',
        'content' => '<p>Kính gửi {{ reservation_name }},</p>
                     <p>Cảm ơn bạn đã đặt bàn! Chúng tôi đã nhận được yêu cầu đặt bàn của bạn với thông tin như sau:</p>
                     <p><strong>Ngày:</strong> {{ reservation_date }}<br>
                     <strong>Giờ:</strong> {{ reservation_time }}<br>
                     <strong>Số lượng khách:</strong> {{ number_of_guests }}</p>
                     <p>Chúng tôi sẽ liên hệ với bạn sớm để xác nhận đặt bàn. Nếu bạn có bất kỳ câu hỏi nào, xin đừng ngần ngại liên hệ với chúng tôi.</p>
                     <p>Trân trọng,<br>Đội ngũ nhà hàng</p>',
    ],
    'reservation-status-update' => [
        'title' => 'Cập nhật trạng thái đặt bàn',
        'description' => 'Email gửi cho khách hàng khi trạng thái đặt bàn được cập nhật',
        'subject' => 'Trạng thái đặt bàn của bạn đã được cập nhật',
        'header' => 'Cập nhật trạng thái đặt bàn',
        'content' => '<p>Kính gửi {{ reservation_name }},</p>
                     <p>Trạng thái đặt bàn của bạn đã được cập nhật thành: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>Chi tiết đặt bàn:</strong><br>
                     <strong>Ngày:</strong> {{ reservation_date }}<br>
                     <strong>Giờ:</strong> {{ reservation_time }}<br>
                     <strong>Số lượng khách:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>Nếu bạn có bất kỳ câu hỏi nào, vui lòng liên hệ với chúng tôi.</p>
                     <p>Trân trọng,<br>Đội ngũ nhà hàng</p>',
    ],
    'reservation_details' => 'Chi tiết đặt bàn',
    'date' => 'Ngày',
    'time' => 'Giờ',
    'guests' => 'Khách',
    'special_requests' => 'Yêu cầu đặc biệt',
    'visit_website' => 'Ghé thăm website của chúng tôi',
    'view_reservation' => 'Xem đặt bàn',
    'cancel_or_modify' => 'Nếu bạn cần hủy hoặc thay đổi đặt bàn, vui lòng liên hệ với chúng tôi qua :restaurant_phone hoặc :restaurant_email.',
    'contact_us' => 'Nếu bạn có bất kỳ câu hỏi nào, vui lòng liên hệ với chúng tôi qua :restaurant_phone hoặc :restaurant_email.',
    'status' => 'Trạng thái',
    'confirmed' => 'Đã xác nhận',
    'cancelled' => 'Đã hủy',
    'note_from_restaurant' => 'Ghi chú từ nhà hàng',
    'make_new_reservation' => 'Đặt bàn mới',
    'status_update' => [
        'approved_title' => 'Đặt bàn đã được xác nhận!',
        'approved_message' => 'Tin tốt! Đặt bàn của bạn đã được xác nhận.',
        'cancelled_title' => 'Đặt bàn đã bị hủy',
        'cancelled_message' => 'Chúng tôi rất tiếc, nhưng đặt bàn của bạn đã bị hủy.',
        'default_title' => 'Cập nhật trạng thái đặt bàn',
        'default_message' => 'Trạng thái đặt bàn của bạn đã được cập nhật.',
    ],
];
