<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Lịch sử thay đổi "Cấu hình quy tắc xếp lịch" (giá trị hiện hành lưu trong bảng settings, khóa roster_scheduling_rules).
        Schema::create('roster_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->json('before')->nullable();
            $table->json('after');
            $table->string('summary', 1000)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('user_name', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_rule_revisions');
    }
};
