<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 200);
            $table->boolean('is_completed')->default(false);
            $table->string('source');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['venture_id', 'position'], 'steps_venture_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps');
    }
};
