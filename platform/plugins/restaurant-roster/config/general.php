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
        // Mỗi outlet góp tối đa bấy nhiêu người cho Lobby trong một khung giờ (quản lý 22/10/2026: 1 người / outlet, Gusto + Villa = 2).
        // Người đi hỗ trợ Lobby KHÔNG được tính vào định biên nhà hàng của ca đó.
        'max_support_per_outlet' => 1,
        // Ngày đông khách (thứ ISO: 6 = thứ Bảy): hạn chế xếp OFF vào những ngày này.
        'busy_weekdays' => [6],
        // Khu trực thuộc một nhà hàng (vd Kid-Deline thuộc Gusto): nhân viên nhà hàng đó đứng khu này không tính là "ngoài outlet chính".
        'outlet_parents' => ['KID' => 'GUSTO'],
        // Ai được đứng khu trực thuộc mà không cần khai báo năng lực từng người (quản lý 22/10/2026: Kid-Deline = bất kỳ nữ Waiter của Gusto).
        // Người outlet khác (vd nữ Villa) chỉ được mượn khi có dòng năng lực tại khu đó và nhà hàng chủ quản thiếu người.
        'child_outlet_staff' => ['KID' => ['gender' => 'female', 'positions' => ['Waiter']]],
        // Ngày đông khách: mỗi outlet × ca được nhận thêm tối đa bấy nhiêu người trên định biên mục tiêu (0 = tắt).
        'busy_day_extra_capacity' => 1,
        // Mỗi outlet × ca được nhận thêm tối đa bấy nhiêu người trên định biên mục tiêu, để ai cũng đủ ngày làm
        // (không phải OFF thêm vì ca đã đủ mục tiêu). Người dôi ra được chia đều giữa các ca.
        'flex_capacity' => 2,
        // Người không có ca cứng: số ca sáng (M6, M9, OH) và chiều (A2) trong tuần lệch nhau tối đa bấy nhiêu ca.
        'shift_mix_tolerance' => 1,
        // Quy định nam / Hostess / quản lý mỗi ca trước đây viết cứng ở đây (staffing_rules) – đã chuyển vào Định biên nhân sự
        // (database, migration 2026_10_22_000004). Thuật toán và bảng xếp ca chỉ đọc định biên trong database.
        // Cấp quản lý (vị trí bật "Cấp quản lý"): thuật toán chỉ tự xếp các ca này, tại outlet chính, không đi Lobby;
        // số quản lý ca sáng / chiều được cân bằng theo từng outlet, từng ngày. OH / M9 / đổi outlet do quản lý xếp tay.
        'manager_shift_codes' => ['M6', 'A2'],
        // Vị trí được tính là "nữ waiter" cho định biên nữ waiter trực tiếp tại outlet (Định biên nhân sự → Nữ waiter tối thiểu / mục tiêu).
        // Không tính cấp quản lý, người đang hỗ trợ Lobby (L) hay đứng Kid-Deline trong ca.
        'female_staff_positions' => ['Waiter'],
        // Vị trí được tính là "nam waiter" cho định biên nam M6 / A2 (Định biên nhân sự → Nam tối thiểu / mục tiêu). Không tính cấp quản lý.
        'male_staff_positions' => ['Waiter'],
        // Điều chuyển giữa outlet nhà hàng (quản lý 23/10/2026: ưu tiên xếp đủ người các outlet, quản lý chỉnh sau):
        // "auto" = waiter / hostess (transfer_positions) và Night đa năng tự đủ điều kiện thay thế ở các outlet transfer_outlets;
        //          người outlet chính vẫn được ưu tiên, không rút người làm outlet nguồn dưới tối thiểu.
        // "skills" = chỉ theo năng lực đã khai; "flex_only" = không tự lấy người outlet nhà hàng khác (trừ Night đa năng).
        // Định biên (quản lý 23/10/2026): "balance" = bỏ định biên ở nhà hàng thông thường (Gusto, Villa…) – mỗi người làm đủ ngày công
        // ở outlet chính, thuật toán cân bằng quản lý / nam / nữ waiter giữa M6 và A2 (open_shift_codes), chia OFF quản lý đều theo ngày.
        // Vẫn giữ định biên của Lobby (định biên phủ), Night, Room Service, Kid-Deline. "requirements" = dùng Định biên nhân sự như trước.
        'staffing_mode' => 'balance',
        'open_shift_codes' => ['M6', 'A2'],
        'cross_outlet_transfer' => 'auto',
        // Năng lực tự suy từ hồ sơ (quản lý 25/10/2026): outlet chính → làm được mọi ca của outlet đó; nữ waiter outlet nhà hàng → hỗ trợ Lobby;
        // nam waiter Villa / Gusto / Night bật "Luân phiên Night" → làm được ca đêm. Mục "Ngoại lệ năng lực" chỉ để khai thêm ngoài mặc định.
        // false = như trước: chỉ theo dòng năng lực đã khai.
        'skills_from_profile' => true,
        // Mã ca thuật toán được TỰ xếp (quản lý 24/10/2026). "OH" (OH ở nhà hàng) không có trong danh sách → chỉ quản lý xếp tay;
        // "OH(L)" là OH hỗ trợ Lobby. "N" = ca Night (luân phiên / Night cứng). Ô quản lý xếp tay hoặc khóa không bị lọc.
        'auto_shift_codes' => ['M6', 'A2', 'N', 'OH(L)', 'M9(L)', 'M6(L)', 'A2(L)', 'M6(K)', 'A2(K)'],
        'transfer_outlets' => ['GUSTO', 'VILLA'],
        'transfer_positions' => ['Waiter', 'Hostess'],
        // Night (quản lý 23/10/2026): đúng 3 người / ngày (Định biên nhân sự), ≥ 1 Night cứng (ca cứng ca đêm) mỗi ngày;
        // mỗi tuần chọn bấy nhiêu nam waiter luân phiên từ các outlet dưới, mỗi người 2 đêm liền nhau (3 khi cần), hôm sau OFF.
        'night_rotation_per_week' => 4,
        // Số đêm tối đa / tuần của một người luân phiên (mục tiêu 2 đêm liền nhau; làm thêm đêm khi cần đủ 3 người / ngày).
        'night_rotation_max_nights' => 5,
        'night_rotation_outlets' => ['GUSTO', 'VILLA', 'NIGHT'],
        // Outlet Room Service (mã outlet) – người chuyên trách / dự phòng / thay thế quản lý ở trang Nhân sự Room Service.
        'room_service_codes' => ['ROOM', 'RS', 'ROOMSERVICE'],
        // Định biên phủ Lobby = số người CÓ MẶT CÙNG LÚC (quản lý chốt 22/10/2026): khung được chia tại các mốc vào / ra Lobby
        // của ca, một người chỉ được tính ở đoạn mà họ có mặt trọn đoạn (không còn "phủ ≥ 50% khung").
        // Ngày OFF: "weekly" = mỗi tuần đúng số ngày OFF cấu hình của nhân viên (quản lý 19/10/2026: bắt buộc).
        // "fortnight" = đủ 2 × số ngày OFF/tuần trong mỗi 2 tuần, mỗi tuần ít nhất min_off_per_week ngày.
        'days_off_mode' => 'weekly',
        'min_off_per_week' => 1,
        // Khoảng đánh giá công bằng (số tuần lịch sử): luân phiên OH(L), M9(L), M6(L), A2(L), Kid-Deline, ca đêm và cân bằng sáng / chiều.
        // Quản lý chọn lại được khi chạy xếp lịch (4 hoặc 8 tuần).
        'history_weeks' => 4,
        // Xếp lịch theo tuần (quản lý 25/10/2026): bỏ bước "Kỳ lịch & OFF" – OFF và ca nhân viên xin ghi thẳng trên Bảng xếp ca rồi chạy xếp tự động.
        // true = như trước: chỉ chạy khi kỳ lịch bao trọn phạm vi đã xác nhận "đã nhập xong OFF".
        'require_off_confirmation' => false,
        // Ca nhân viên xin (nhắn trong nhóm): quản lý ghi trên Bảng xếp ca và khóa giống OFF xin.
        'request_shift_codes' => ['M6', 'A2'],
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
        'L' => ['label' => 'Lobby', 'description' => 'Hỗ trợ quầy bar Lobby (cần 2 nữ waiter cùng lúc 09:00–16:45): OH(L) 08:45–16:45 (Nha Trang), M9(L) 09:00–17:00 (Cam Ranh); thay thế: M6(L) 09–14h ở Lobby, A2(L) 14–17h ở Lobby rồi về nhà hàng'],
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
