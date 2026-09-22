<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('syllabus_topics', function (Blueprint $table) {
            $table->string('term', 16)->nullable();
            $table->unsignedInteger('week_number')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('week_number')->nullable();
            $table->json('suggested_bloom_levels')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['week_number', 'suggested_bloom_levels']);
        });

        Schema::table('syllabus_topics', function (Blueprint $table) {
            $table->dropColumn(['term', 'week_number']);
        });
    }
};
