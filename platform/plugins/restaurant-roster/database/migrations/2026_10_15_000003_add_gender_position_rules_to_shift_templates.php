<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_shift_templates', function (Blueprint $table): void {
            // Ca chỉ dành cho một giới tính / một vị trí (vd M9: nữ, Waiter, ở Cam Ranh).
            $table->string('allowed_gender', 10)->nullable()->after('allowed_residence');
            $table->foreignId('allowed_position_id')->nullable()->after('allowed_gender');
        });
    }

    public function down(): void
    {
        Schema::table('roster_shift_templates', function (Blueprint $table): void {
            $table->dropColumn(['allowed_gender', 'allowed_position_id']);
        });
    }
};
