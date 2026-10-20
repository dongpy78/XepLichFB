<?php

return [
    'new-reservation' => [
        'title' => 'การแจ้งเตือนการจองใหม่',
        'description' => 'อีเมลที่ส่งถึงผู้ดูแลระบบเมื่อมีการจองใหม่',
        'subject' => 'การจองใหม่จาก {{ reservation_name }}',
        'header' => 'ได้รับการจองใหม่!',
        'content' => '<p>คุณได้รับการจองใหม่พร้อมรายละเอียดดังนี้:</p>
                     <p><strong>ชื่อ:</strong> {{ reservation_name }}<br>
                     <strong>อีเมล:</strong> {{ reservation_email }}<br>
                     <strong>โทรศัพท์:</strong> {{ reservation_phone }}<br>
                     <strong>วันที่:</strong> {{ reservation_date }}<br>
                     <strong>เวลา:</strong> {{ reservation_time }}<br>
                     <strong>จำนวนผู้เข้าใช้บริการ:</strong> {{ number_of_guests }}<br>
                     <strong>คำขอพิเศษ:</strong> {{ special_requests }}</p>
                     <p>กรุณาเข้าสู่ระบบแผงควบคุมของคุณเพื่อจัดการการจองนี้</p>',
    ],
    'reservation-confirmation' => [
        'title' => 'ยืนยันการจอง',
        'description' => 'อีเมลที่ส่งถึงลูกค้าหลังจากส่งการจอง',
        'subject' => 'ยืนยันการจองของคุณ',
        'header' => 'ยืนยันการจอง',
        'content' => '<p>เรียน {{ reservation_name }},</p>
                     <p>ขอบคุณสำหรับการจองของคุณ! เราได้รับคำขอการจองของคุณพร้อมรายละเอียดดังนี้:</p>
                     <p><strong>วันที่:</strong> {{ reservation_date }}<br>
                     <strong>เวลา:</strong> {{ reservation_time }}<br>
                     <strong>จำนวนผู้เข้าใช้บริการ:</strong> {{ number_of_guests }}</p>
                     <p>เราจะติดต่อคุณในเร็วๆ นี้เพื่อยืนยันการจองของคุณ หากคุณมีคำถามใดๆ กรุณาอย่าลังเลที่จะติดต่อเรา</p>
                     <p>ขอแสดงความนับถือ,<br>ทีมร้านอาหาร</p>',
    ],
    'reservation-status-update' => [
        'title' => 'การอัปเดตสถานะการจอง',
        'description' => 'อีเมลที่ส่งถึงลูกค้าเมื่อสถานะการจองได้รับการอัปเดต',
        'subject' => 'สถานะการจองของคุณได้รับการอัปเดตแล้ว',
        'header' => 'การอัปเดตสถานะการจอง',
        'content' => '<p>เรียน {{ reservation_name }},</p>
                     <p>สถานะการจองของคุณได้รับการอัปเดตเป็น: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>รายละเอียดการจอง:</strong><br>
                     <strong>วันที่:</strong> {{ reservation_date }}<br>
                     <strong>เวลา:</strong> {{ reservation_time }}<br>
                     <strong>จำนวนผู้เข้าใช้บริการ:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>หากคุณมีคำถามใดๆ กรุณาติดต่อเรา</p>
                     <p>ขอแสดงความนับถือ,<br>ทีมร้านอาหาร</p>',
    ],
    'reservation_details' => 'รายละเอียดการจอง',
    'date' => 'วันที่',
    'time' => 'เวลา',
    'guests' => 'ผู้เข้าใช้บริการ',
    'special_requests' => 'คำขอพิเศษ',
    'visit_website' => 'เยี่ยมชมเว็บไซต์ของเรา',
    'view_reservation' => 'ดูการจอง',
    'cancel_or_modify' => 'หากคุณต้องการยกเลิกหรือแก้ไขการจองของคุณ กรุณาติดต่อเราที่ :restaurant_phone หรือ :restaurant_email',
    'contact_us' => 'หากคุณมีคำถามใดๆ กรุณาติดต่อเราที่ :restaurant_phone หรือ :restaurant_email',
    'status' => 'สถานะ',
    'confirmed' => 'ยืนยันแล้ว',
    'cancelled' => 'ยกเลิกแล้ว',
    'note_from_restaurant' => 'หมายเหตุจากร้านอาหาร',
    'make_new_reservation' => 'จองใหม่',
    'status_update' => [
        'approved_title' => 'ยืนยันการจองแล้ว!',
        'approved_message' => 'ข่าวดี! การจองของคุณได้รับการยืนยันแล้ว',
        'cancelled_title' => 'ยกเลิกการจองแล้ว',
        'cancelled_message' => 'เราขออภัย แต่การจองของคุณถูกยกเลิกแล้ว',
        'default_title' => 'การอัปเดตสถานะการจอง',
        'default_message' => 'สถานะการจองของคุณได้รับการอัปเดตแล้ว',
    ],
];
