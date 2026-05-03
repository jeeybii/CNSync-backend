<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_items', function (Blueprint $table) {
            $table->string('question_type', 32)->default('multiple_choice')->after('bloom_level');
        });
    }

    public function down(): void
    {
        Schema::table('question_items', function (Blueprint $table) {
            $table->dropColumn('question_type');
        });
    }
};
