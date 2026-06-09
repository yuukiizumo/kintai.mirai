<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_management_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->string('work_rating')->nullable();
            $table->string('life_rating')->nullable();
            $table->text('monthly_reflection')->nullable();
            $table->text('next_month_goal')->nullable();
            $table->text('skill_progress')->nullable();
            $table->string('activity_status')->nullable();
            $table->text('activity_detail')->nullable();
            $table->text('other')->nullable();
            $table->text('admin_comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'report_date']);
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_management_reports');
    }
};
