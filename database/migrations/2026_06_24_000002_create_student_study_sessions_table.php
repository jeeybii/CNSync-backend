<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_deck_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_cards');
            $table->unsignedInteger('cards_completed')->default(0);
            $table->unsignedInteger('hints_used')->default(0);
            $table->unsignedInteger('reveals_used')->default(0);
            $table->unsignedInteger('xp_earned')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'student_deck_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_study_sessions');
    }
};
