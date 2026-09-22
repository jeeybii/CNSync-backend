<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_topics', function (Blueprint $table): void {
            $table->unsignedInteger('position')->nullable();
        });

        $positions = [];
        foreach (DB::table('syllabus_topics')->orderBy('document_id')->orderBy('id')->get(['id', 'document_id']) as $topic) {
            $positions[$topic->document_id] = ($positions[$topic->document_id] ?? 0) + 1;
            DB::table('syllabus_topics')->where('id', $topic->id)->update(['position' => $positions[$topic->document_id]]);
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('syllabus_topic_id')->nullable()->constrained('syllabus_topics')->nullOnDelete();
        });

        Schema::table('tos_allocations', function (Blueprint $table): void {
            $table->foreignId('syllabus_topic_id')->nullable()->constrained('syllabus_topics')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tos_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('syllabus_topic_id');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('syllabus_topic_id');
        });

        Schema::table('syllabus_topics', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
