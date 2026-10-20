<?php

return [
    'new-reservation' => [
        'title' => '새 예약 알림',
        'description' => '새 예약이 제출될 때 관리자에게 전송되는 이메일',
        'subject' => '{{ reservation_name }}님의 새 예약',
        'header' => '새 예약이 접수되었습니다!',
        'content' => '<p>다음 세부정보로 새 예약이 접수되었습니다:</p>
                     <p><strong>이름:</strong> {{ reservation_name }}<br>
                     <strong>이메일:</strong> {{ reservation_email }}<br>
                     <strong>전화번호:</strong> {{ reservation_phone }}<br>
                     <strong>날짜:</strong> {{ reservation_date }}<br>
                     <strong>시간:</strong> {{ reservation_time }}<br>
                     <strong>손님 수:</strong> {{ number_of_guests }}<br>
                     <strong>특별 요청사항:</strong> {{ special_requests }}</p>
                     <p>이 예약을 관리하려면 관리자 패널에 로그인하세요.</p>',
    ],
    'reservation-confirmation' => [
        'title' => '예약 확인',
        'description' => '예약 제출 후 고객에게 전송되는 이메일',
        'subject' => '예약 확인',
        'header' => '예약 확인',
        'content' => '<p>{{ reservation_name }}님께,</p>
                     <p>예약해 주셔서 감사합니다! 다음 세부정보로 예약 요청을 받았습니다:</p>
                     <p><strong>날짜:</strong> {{ reservation_date }}<br>
                     <strong>시간:</strong> {{ reservation_time }}<br>
                     <strong>손님 수:</strong> {{ number_of_guests }}</p>
                     <p>예약을 확인하기 위해 곧 연락드리겠습니다. 궁금한 사항이 있으시면 언제든지 문의해 주세요.</p>
                     <p>감사합니다,<br>레스토랑 팀</p>',
    ],
    'reservation-status-update' => [
        'title' => '예약 상태 업데이트',
        'description' => '예약 상태가 업데이트될 때 고객에게 전송되는 이메일',
        'subject' => '예약 상태가 업데이트되었습니다',
        'header' => '예약 상태 업데이트',
        'content' => '<p>{{ reservation_name }}님께,</p>
                     <p>예약 상태가 다음으로 업데이트되었습니다: <strong>{{ reservation_status }}</strong></p>
                     <p><strong>예약 세부정보:</strong><br>
                     <strong>날짜:</strong> {{ reservation_date }}<br>
                     <strong>시간:</strong> {{ reservation_time }}<br>
                     <strong>손님 수:</strong> {{ number_of_guests }}</p>
                     <p>{{ admin_notes }}</p>
                     <p>궁금한 사항이 있으시면 문의해 주세요.</p>
                     <p>감사합니다,<br>레스토랑 팀</p>',
    ],
    'reservation_details' => '예약 세부정보',
    'date' => '날짜',
    'time' => '시간',
    'guests' => '손님',
    'special_requests' => '특별 요청사항',
    'visit_website' => '웹사이트 방문',
    'view_reservation' => '예약 보기',
    'cancel_or_modify' => '예약을 취소하거나 수정하려면 :restaurant_phone 또는 :restaurant_email로 문의하세요.',
    'contact_us' => '궁금한 사항이 있으시면 :restaurant_phone 또는 :restaurant_email로 문의하세요.',
    'status' => '상태',
    'confirmed' => '확인됨',
    'cancelled' => '취소됨',
    'note_from_restaurant' => '레스토랑의 메모',
    'make_new_reservation' => '새 예약하기',
    'status_update' => [
        'approved_title' => '예약이 확인되었습니다!',
        'approved_message' => '좋은 소식입니다! 예약이 확인되었습니다.',
        'cancelled_title' => '예약이 취소되었습니다',
        'cancelled_message' => '죄송합니다만, 예약이 취소되었습니다.',
        'default_title' => '예약 상태 업데이트',
        'default_message' => '예약 상태가 업데이트되었습니다.',
    ],
];
