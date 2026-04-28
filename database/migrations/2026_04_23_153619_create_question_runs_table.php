<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tos_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('requested_items');
            $table->unsignedInteger('generated_items')->default(0);
            $table->string('status', 32)->default('pending');
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_runs');
    }
};
