<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('visibility', 16);
            $table->string('course_code', 64)->nullable();
            $table->string('course_title', 255)->nullable();
            $table->string('faculty_name', 255)->nullable();
            $table->string('exam_type', 64);
            $table->unsignedInteger('number_of_items');
            $table->json('tos');
            $table->json('questions');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decks');
    }
};
