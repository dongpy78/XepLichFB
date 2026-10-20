<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_coverage_requirements', function (Blueprint $table): void {
            // Chỉ các ca này được tính phủ khung (vd sáng "OH,M9", chiều "A2"); trống = mọi ca có khung (L) hoặc làm trọn ở Lobby.
            $table->string('shift_codes', 100)->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('roster_coverage_requirements', function (Blueprint $table): void {
            $table->dropColumn('shift_codes');
        });
    }
};
