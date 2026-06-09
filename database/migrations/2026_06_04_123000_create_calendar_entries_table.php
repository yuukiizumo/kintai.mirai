<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->boolean('processed')->default(false);
            $table->string('type');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
    }
};
