<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_call_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['owner_id', 'day'], 'ai_call_counters_owner_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_call_counters');
    }
};
