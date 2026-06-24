<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_items', function (Blueprint $table) {
            $table->text('answer_key')->change();
        });
    }

    public function down(): void
    {
        Schema::table('question_items', function (Blueprint $table) {
            $table->string('answer_key')->change();
        });
    }
};
