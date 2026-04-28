<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_run_id')->constrained()->cascadeOnDelete();
            $table->string('topic_name');
            $table->string('bloom_level', 32);
            $table->unsignedInteger('sequence');
            $table->text('question_text');
            $table->json('options');
            $table->string('answer_key');
            $table->json('citations')->nullable();
            $table->timestamps();

            $table->unique(['question_run_id', 'sequence']);
            $table->index(['question_run_id', 'topic_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_items');
    }
};
