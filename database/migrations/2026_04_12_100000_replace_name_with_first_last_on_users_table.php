<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
        });

        foreach (DB::table('users')->cursor() as $row) {
            $name = (string) ($row->name ?? '');
            $parts = preg_split('/\s+/', trim($name), 2, PREG_SPLIT_NO_EMPTY);
            $first = $parts[0] ?? 'User';
            $last = $parts[1] ?? '';

            DB::table('users')->where('id', $row->id)->update([
                'first_name' => $first,
                'last_name' => $last,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('');
        });

        foreach (DB::table('users')->cursor() as $row) {
            $full = trim($row->first_name.' '.$row->last_name);
            DB::table('users')->where('id', $row->id)->update([
                'name' => $full !== '' ? $full : 'User',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
