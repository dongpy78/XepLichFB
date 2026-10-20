<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dữ liệu cho Scheduling Engine: năng lực nhân viên, định biên, đơn OFF,
 * kỳ lịch, khóa ô, lần chạy thuật toán, cảnh báo và nhật ký thay đổi.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_employees', function (Blueprint $table): void {
            // male | female | null (chưa khai báo)
            $table->string('gender', 10)->nullable()->after('name');
            $table->date('hire_date')->nullable()->after('birthday');
            // Số ngày OFF mong muốn mỗi tuần (cấu hình riêng từng người).
            $table->unsignedTinyInteger('days_off_per_week')->default(2)->after('weekly_hours');
            // Có đưa vào thuật toán xếp lịch tự động hay không.
            $table->boolean('auto_schedule')->default(true)->after('days_off_per_week');
        });

        // Năng lực: nhân viên được phép làm outlet/ca nào. Không có dòng = không đủ điều kiện.
        Schema::create('roster_employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            $table->foreignId('outlet_id');
            // null = mọi ca của outlet.
            $table->foreignId('shift_template_id')->nullable();
            // primary = chuyên trách, eligible = đủ điều kiện thay thế.
            $table->string('level', 10)->default('eligible');
            // Ca cứng: thuật toán không chuyển người này sang ca/outlet khác.
            $table->boolean('is_fixed')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'outlet_id', 'shift_template_id'], 'roster_skill_unique');
        });

        // Định biên theo outlet × ca × (thứ trong tuần | ngày cụ thể) × (điều kiện tầng trên).
        Schema::create('roster_staffing_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->index();
            $table->foreignId('shift_template_id');
            // 1 = T2 ... 7 = CN; null = mọi ngày.
            $table->unsignedTinyInteger('weekday')->nullable();
            // Ngày cụ thể (ưu tiên hơn weekday) để chỉnh định biên giữa kỳ.
            $table->date('date')->nullable();
            // null = áp dụng mọi trạng thái; 1 = chỉ khi mở tầng trên; 0 = chỉ khi không mở.
            $table->boolean('upper_floor')->nullable();
            $table->unsignedTinyInteger('min_staff')->default(0);
            $table->unsignedTinyInteger('target_staff')->default(0);
            $table->unsignedTinyInteger('max_staff')->nullable();
            $table->unsignedTinyInteger('min_male')->default(0);
            $table->unsignedTinyInteger('target_male')->default(0);
            // Quản lý cho phép giảm từ mục tiêu xuống tối thiểu khi thiếu người.
            $table->boolean('allow_reduce')->default(true);
            // Định biên gợi ý từ lịch cũ phải được quản lý xác nhận.
            $table->boolean('is_confirmed')->default(false);
            $table->string('source', 20)->default('manual');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        // Trạng thái vận hành theo ngày của outlet (Villa mở tầng trên, đóng cửa...).
        Schema::create('roster_outlet_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id');
            $table->date('date');
            $table->boolean('upper_floor_open')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'date']);
        });

        // Đơn xin OFF / ngày bận / sự kiện tập thể. Chỉ status=approved mới chặn phân công.
        Schema::create('roster_availability_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            $table->date('date_from');
            $table->date('date_to');
            // off_request | busy | group_event | annual_leave | sick
            $table->string('type', 20);
            $table->string('group_label', 120)->nullable();
            $table->string('reason', 255)->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['date_from', 'date_to', 'status']);
        });

        // Quy tắc chuyển tiếp giữa hai ca liên tiếp (ghi đè quy tắc theo giờ nghỉ).
        Schema::create('roster_shift_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_template_id');
            $table->foreignId('to_template_id');
            // forbidden | heavy | allowed
            $table->string('rule', 10);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['from_template_id', 'to_template_id'], 'roster_transition_unique');
        });

        // Kỳ lịch (thường theo tháng).
        Schema::create('roster_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            // draft | review | confirmed | published
            $table->string('status', 20)->default('draft');
            // Tăng mỗi khi lịch trong kỳ thay đổi; dùng để chặn áp dụng kết quả tối ưu đã cũ.
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false)->after('status');
            // manual | engine | import | copy
            $table->string('source', 10)->default('manual')->after('is_locked');
            $table->foreignId('run_id')->nullable()->after('source');
        });

        // Lịch sử các lần chạy thuật toán.
        Schema::create('roster_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->nullable()->index();
            // generate | reoptimize | validate
            $table->string('type', 20);
            $table->date('scope_from');
            $table->date('scope_to');
            $table->json('outlet_ids')->nullable();
            // running | feasible | feasible_with_shortage | infeasible | timeout | error | applied | discarded | stale
            $table->string('status', 30)->default('running');
            $table->string('solver', 60)->nullable();
            $table->unsignedInteger('base_version')->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->json('settings')->nullable();
            $table->json('metrics')->nullable();
            $table->longText('proposal')->nullable();
            $table->text('explanation')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // Cảnh báo / vi phạm sinh ra bởi lần chạy hoặc lần kiểm tra lịch.
        Schema::create('roster_violations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->date('date')->nullable();
            $table->foreignId('outlet_id')->nullable();
            $table->foreignId('shift_template_id')->nullable();
            $table->foreignId('employee_id')->nullable();
            // hard | shortage | fairness | preference | operations
            $table->string('category', 20);
            // critical | high | medium | low
            $table->string('severity', 10);
            $table->string('code', 40);
            $table->string('message', 500);
            $table->json('suggestions')->nullable();
            $table->timestamps();
        });

        // Nhật ký thay đổi ô lịch (thủ công, áp dụng kết quả thuật toán, công bố...).
        Schema::create('roster_change_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            $table->date('date');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            // manual | apply_run | publish | lock | unlock | delete
            $table->string('action', 20);
            $table->foreignId('run_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_change_logs');
        Schema::dropIfExists('roster_violations');
        Schema::dropIfExists('roster_runs');

        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->dropColumn(['is_locked', 'source', 'run_id']);
        });

        Schema::dropIfExists('roster_periods');
        Schema::dropIfExists('roster_shift_transitions');
        Schema::dropIfExists('roster_availability_requests');
        Schema::dropIfExists('roster_outlet_days');
        Schema::dropIfExists('roster_staffing_requirements');
        Schema::dropIfExists('roster_employee_skills');

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn(['gender', 'hire_date', 'days_off_per_week', 'auto_schedule']);
        });
    }
};
