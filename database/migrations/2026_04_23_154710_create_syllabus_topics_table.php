<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('topic_name');
            $table->decimal('hours', 8, 2);
            $table->text('objective')->nullable();
            $table->string('bloom_level', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_topics');
    }
};
