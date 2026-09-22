<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->json('suggested_bloom_levels_by_term')->nullable();
            $table->json('suggested_question_type_percentages_by_term')->nullable();
        });

        Schema::table('tos_runs', function (Blueprint $table): void {
            $table->json('question_type_distribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tos_runs', function (Blueprint $table): void {
            $table->dropColumn('question_type_distribution');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['suggested_bloom_levels_by_term', 'suggested_question_type_percentages_by_term']);
        });
    }
};
