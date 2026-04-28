<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tos_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tos_run_id')->constrained()->cascadeOnDelete();
            $table->string('topic_name');
            $table->decimal('topic_hours', 8, 2);
            $table->decimal('topic_weight', 10, 6);
            $table->string('bloom_level', 32);
            $table->unsignedInteger('item_count');
            $table->timestamps();

            $table->index(['tos_run_id', 'topic_name']);
            $table->index(['tos_run_id', 'bloom_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tos_allocations');
    }
};
