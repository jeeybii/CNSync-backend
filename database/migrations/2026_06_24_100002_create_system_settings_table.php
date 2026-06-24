<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->json('value');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        $now = now();
        DB::table('system_settings')->insert([
            [
                'key' => 'max_file_size_mb',
                'value' => json_encode(64),
                'label' => 'Max File Size (MB)',
                'description' => 'Maximum size in megabytes allowed per uploaded document.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'allowed_file_types',
                'value' => json_encode(['pdf', 'docx', 'pptx', 'txt']),
                'label' => 'Allowed File Types',
                'description' => 'File extensions accepted for syllabus and learning material uploads.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'max_materials_per_assessment',
                'value' => json_encode(10),
                'label' => 'Max Materials Per Assessment',
                'description' => 'Maximum number of learning material files allowed per assessment generation.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'max_student_materials_per_deck',
                'value' => json_encode(5),
                'label' => 'Max Materials Per Student Deck',
                'description' => 'Maximum number of material files a student can upload when generating a reviewer deck.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
