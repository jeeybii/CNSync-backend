<?php

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'disk' => 'local',
            'path' => 'documents/1/placeholder.pdf',
            'original_name' => 'placeholder.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'kind' => DocumentKind::Material,
            'status' => DocumentStatus::Uploaded,
        ];
    }
}
