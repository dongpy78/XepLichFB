<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table): void {
            $table->longText('raw_content')->nullable();
        });

        Schema::table('blocks_translations', function (Blueprint $table): void {
            $table->longText('raw_content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table): void {
            $table->dropColumn('raw_content');
        });

        Schema::table('blocks_translations', function (Blueprint $table): void {
            $table->dropColumn('raw_content');
        });
    }
};
