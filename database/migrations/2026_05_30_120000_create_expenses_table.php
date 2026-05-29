<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('description', 200);
            $table->date('date');
            $table->timestamps();

            $table->index(['venture_id', 'date'], 'expenses_venture_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
