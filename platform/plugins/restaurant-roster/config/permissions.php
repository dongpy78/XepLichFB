<?php

return [
    [
        'name' => 'Xếp lịch nhà hàng',
        'flag' => 'roster.index',
    ],
    [
        'name' => 'Quản lý & phân công ca',
        'flag' => 'roster.manage',
        'parent_flag' => 'roster.index',
    ],
    [
        'name' => 'Tự động xếp lịch (chạy thuật toán, áp dụng phương án)',
        'flag' => 'roster.auto',
        'parent_flag' => 'roster.index',
    ],
    [
        'name' => 'Ghi / gỡ OFF của nhân viên, mở khóa ô, duyệt tăng ca',
        'flag' => 'roster.approve',
        'parent_flag' => 'roster.index',
    ],
    [
        'name' => 'Danh mục (nhà hàng, vị trí, nhân viên, mẫu ca, năng lực, định biên)',
        'flag' => 'roster.settings',
        'parent_flag' => 'roster.index',
    ],
];
