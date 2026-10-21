<?php

return [
    // Quy tắc kiểm tra xung đột (Bộ luật Lao động 2019).
    'rules' => [
        // Nghỉ tối thiểu giữa hai ca (Điều 109: ít nhất 12 giờ trước khi chuyển ca).
        'min_rest_hours' => 12,
        // Tổng giờ làm bình thường + thêm giờ trong một ngày (Điều 107).
        'max_daily_hours' => 12,
        // Số giờ làm thêm tối đa / tuần trước khi cảnh báo mức cao.
        'max_weekly_overtime' => 12,
        // Số ngày làm liên tiếp tối đa (Điều 111: nghỉ ít nhất 24 giờ liên tục mỗi tuần).
        'max_consecutive_days' => 6,
    ],

    // Thuật toán xếp lịch tự động (Scheduling Engine).
    'scheduling' => [
        // Nghỉ dưới mức này giữa hai ca liên tiếp: cấm hẳn (vd N 22–06 rồi M6 06:00).
        'hard_min_rest_hours' => 8,
        // Nghỉ dưới mức này nhưng >= mức cứng: được phép nhưng là "ca nặng" (vd A2 → M6),
        // thuật toán hạn chế và chia đều (quyết định của quản lý ngày 12/10/2026).
        'soft_min_rest_hours' => 12,
        'max_daily_hours' => 12,
        'max_consecutive_days' => 6,
        // Giới hạn thời gian mỗi lần chạy (giây) – giữ dưới timeout của web server.
        'time_limit_seconds' => 20,
        'max_iterations' => 400000,
        'seed' => 20261012,
        // Ca có outlet cố định: ca này luôn được tính cho outlet đó, bất kể outlet chính của nhân viên.
        'shift_outlet' => [
            'N' => 'NIGHT',
            // M9: làm trọn ca tại quầy bar Lobby. OH làm ở nhà hàng; OH(L) = OH có hỗ trợ Lobby (quản lý 15/10/2026).
            'M9' => 'LOBBY',
        ],
        // Trạm hỗ trợ: ký hiệu (L) = lên hỗ trợ quầy bar Lobby trong khung giờ cấu hình ở từng mẫu ca.
        'station_code' => 'LOBBY',
        // Hỗ trợ Lobby (L) và Kid Deline (K) chỉ dành cho nhân viên nữ.
        'support_gender' => 'female',
        'female_only_tags' => ['L', 'K'],
        // Mỗi outlet góp tối đa bấy nhiêu người cho Lobby trong một ca (khung giờ) – bar thiếu người mới lấy người nhà hàng qua.
        // Người đi hỗ trợ Lobby KHÔNG được tính vào định biên nhà hàng của ca đó.
        'max_support_per_outlet' => 2,
        // Ngày đông khách (thứ ISO: 6 = thứ Bảy): hạn chế xếp OFF vào những ngày này.
        'busy_weekdays' => [6],
        // Khu trực thuộc một nhà hàng (vd Kid-Deline thuộc Gusto): nhân viên nhà hàng đó đứng khu này không tính là "ngoài outlet chính".
        'outlet_parents' => ['KID' => 'GUSTO'],
        // Ngày đông khách: mỗi outlet × ca được nhận thêm tối đa bấy nhiêu người trên định biên mục tiêu (0 = tắt).
        'busy_day_extra_capacity' => 1,
        // Mỗi outlet × ca được nhận thêm tối đa bấy nhiêu người trên định biên mục tiêu, để ai cũng đủ ngày làm
        // (không phải OFF thêm vì ca đã đủ mục tiêu). Người dôi ra được chia đều giữa các ca.
        'flex_capacity' => 2,
        // Người không có ca cứng: số ca sáng (M6, M9, OH) và chiều (A2) trong tuần lệch nhau tối đa bấy nhiêu ca.
        'shift_mix_tolerance' => 1,
        // Quy định bắt buộc theo outlet × ca (mã outlet / mã ca), tính ở mức tối thiểu:
        // - min_male: số nam tối thiểu (thiếu nam thì đưa người outlet NIGHT sang bù);
        // - position + min: số người tối thiểu của một vị trí.
        'staffing_rules' => [
            ['outlets' => ['GUSTO', 'VILLA'], 'shifts' => ['M6', 'A2'], 'min_male' => 1],
            ['outlets' => ['GUSTO', 'VILLA'], 'shifts' => ['M6', 'A2'], 'position' => 'Hostess', 'min' => 1],
            // Mỗi ca có người quản lý: các vị trí bật "Cấp quản lý" trên màn hình Vị trí (Junior Restaurant Captain trở lên).
            ['outlets' => ['GUSTO', 'VILLA'], 'shifts' => ['M6', 'A2'], 'label' => 'Quản lý (Junior Captain trở lên)', 'min' => 1, 'managers' => true],
        ],
        // Cấp quản lý (vị trí bật "Cấp quản lý"): thuật toán chỉ tự xếp các ca này, tại outlet chính, không đi Lobby;
        // số quản lý ca sáng / chiều được cân bằng theo từng outlet, từng ngày. OH / M9 / đổi outlet do quản lý xếp tay.
        'manager_shift_codes' => ['M6', 'A2'],
        // Một người được tính là phủ một khung giờ Lobby nếu có mặt ở Lobby ít nhất tỉ lệ này của khung.
        'coverage_overlap_ratio' => 0.5,
        // Ngày OFF: "weekly" = mỗi tuần đúng số ngày OFF cấu hình của nhân viên (quản lý 19/10/2026: bắt buộc).
        // "fortnight" = đủ 2 × số ngày OFF/tuần trong mỗi 2 tuần, mỗi tuần ít nhất min_off_per_week ngày.
        'days_off_mode' => 'weekly',
        'min_off_per_week' => 1,
        // Chỉ chạy "Tạo lịch tự động" / "Tối ưu lại" khi kỳ lịch bao trọn phạm vi đã được xác nhận "đã nhập xong OFF".
        'require_off_confirmation' => true,
        // Giới hạn tăng ca / tháng (Bộ luật Lao động 2019, Điều 107: không quá 40 giờ/tháng).
        'max_monthly_overtime_hours' => 40,
    ],

    // Loại đơn đăng ký ngày bận / xin nghỉ. Chỉ đơn ĐÃ DUYỆT mới chặn xếp ca.
    'availability_types' => [
        'off_request' => 'Xin OFF',
        'busy' => 'Ngày bận',
        'group_event' => 'Sự kiện tập thể',
        'annual_leave' => 'Nghỉ phép năm',
        'sick' => 'Nghỉ ốm',
    ],

    // Khu vực sinh sống – dùng cho ca phụ thuộc xe đưa đón (OH chỉ dành cho nhân viên ở Nha Trang).
    'residences' => [
        'cam_ranh' => 'Cam Ranh',
        'nha_trang' => 'Nha Trang (xe đưa đón)',
    ],

    'leave_types' => [
        'AL' => 'Nghỉ phép năm',
        'SL' => 'Nghỉ ốm',
        'UL' => 'Nghỉ không lương',
        'CO' => 'Nghỉ bù',
    ],

    // Ký hiệu phụ trong ngoặc của ca, ví dụ A2(L)(BQ), M6(RS)+3.
    // 'outlet' => mã outlet: ca có ký hiệu này được tính là làm tại outlet đó (mượn người).
    'shift_tags' => [
        'L' => ['label' => 'Lobby', 'description' => 'Hỗ trợ quầy bar Lobby: A2(L) 14–17h rồi về nhà hàng, OH(L) cả ca, M9(L) làm trọn ca ở Lobby'],
        'G' => ['label' => '→ Gusto', 'description' => 'Thiếu người – sang làm cả ca tại nhà hàng Gusto (tính định biên cho Gusto)', 'outlet' => 'GUSTO'],
        'V' => ['label' => '→ Villa', 'description' => 'Thiếu người – sang làm cả ca tại nhà hàng Villa (tính định biên cho Villa)', 'outlet' => 'VILLA'],
        'RS' => ['label' => 'Room Service', 'description' => 'Đi Room Service', 'outlet' => 'ROOM'],
        'RS1' => ['label' => 'RS học việc', 'description' => 'Theo hỗ trợ và học việc Room Service', 'outlet' => 'ROOM'],
        'BQ' => ['label' => 'Tiệc', 'description' => 'Chạy tiệc (Banquet)'],
        'K' => ['label' => 'Kid-Deline', 'description' => 'Đứng Kid-Deline của nhà hàng Gusto (nữ; nhân viên Villa sang đứng ghi thêm (V), vd A2(V)(K))', 'outlet' => 'KID'],
    ],

    'employment_types' => [
        'FT' => 'Toàn thời gian',
        'PT' => 'Bán thời gian',
        'TR' => 'Thực tập',
        'CA' => 'Thời vụ',
    ],

    // Bảng màu ca, khớp với thiết kế Noa Roster Operations.
    'colors' => [
        'sky' => 'Xanh trời – Ca sáng',
        'sand' => 'Cát – Ca chiều',
        'amber' => 'Hổ phách – Ca gãy',
        'navy' => 'Navy – Ca tối / Tiệc',
        'violet' => 'Tím – Sự kiện',
        'emerald' => 'Ngọc lục bảo',
        'slate' => 'Xám',
    ],
];
