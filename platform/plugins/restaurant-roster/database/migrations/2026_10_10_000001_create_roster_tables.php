<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('roster_outlets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->string('description', 400)->nullable();
            $table->integer('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();
        });

        Schema::create('roster_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 400)->nullable();
            $table->integer('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();
        });

        Schema::create('roster_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->foreignId('outlet_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->string('employment_type', 10)->default('FT');
            $table->decimal('weekly_hours', 5, 2)->default(48);
            $table->string('status', 60)->default('published');
            $table->timestamps();
        });

        Schema::create('roster_shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->time('start_time');
            $table->time('end_time');
            $table->time('split_start_time')->nullable();
            $table->time('split_end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('color', 20)->default('sky');
            $table->integer('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();
        });

        Schema::create('roster_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            $table->foreignId('outlet_id')->nullable()->index();
            $table->date('date');
            // shift | off | leave
            $table->string('kind', 10)->default('shift');
            $table->foreignId('shift_template_id')->nullable();
            $table->string('leave_type', 10)->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->dateTime('split_start_at')->nullable();
            $table->dateTime('split_end_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->decimal('hours', 5, 2)->default(0);
            $table->string('note', 255)->nullable();
            // draft | published
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shifts');
        Schema::dropIfExists('roster_shift_templates');
        Schema::dropIfExists('roster_employees');
        Schema::dropIfExists('roster_positions');
        Schema::dropIfExists('roster_outlets');
    }
};
