<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->unsignedTinyInteger('number_of_sets')->default(1)->after('number_of_items');
            $table->json('set_b_sequence')->nullable()->after('number_of_sets');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['number_of_sets', 'set_b_sequence']);
        });
    }
};
