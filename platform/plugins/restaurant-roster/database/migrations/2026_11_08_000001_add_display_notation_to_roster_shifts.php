<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_shifts', function (Blueprint $table): void {
            // Ký hiệu quản lý tự nhập (vd "M6(EL)"): hiển thị và in đúng nguyên văn.
            $table->string('display_notation', 40)->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->dropColumn('display_notation');
        });
    }
};
