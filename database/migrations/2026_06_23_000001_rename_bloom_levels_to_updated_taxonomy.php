<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array RENAME_MAP = [
        'knowledge' => 'remembering',
        'understand' => 'understanding',
        'apply' => 'applying',
        'analyze' => 'analyzing',
        'evaluate' => 'evaluating',
        'create' => 'creating',
    ];

    private const array REVERSE_MAP = [
        'remembering' => 'knowledge',
        'understanding' => 'understand',
        'applying' => 'apply',
        'analyzing' => 'analyze',
        'evaluating' => 'evaluate',
        'creating' => 'create',
    ];

    public function up(): void
    {
        $this->renameStringColumn('tos_allocations', 'bloom_level', self::RENAME_MAP);
        $this->renameStringColumn('question_items', 'bloom_level', self::RENAME_MAP);
        $this->renameStringColumn('syllabus_topics', 'bloom_level', self::RENAME_MAP);
        $this->renameJsonKeys('tos_runs', 'bloom_distribution', self::RENAME_MAP);
    }

    public function down(): void
    {
        $this->renameStringColumn('tos_allocations', 'bloom_level', self::REVERSE_MAP);
        $this->renameStringColumn('question_items', 'bloom_level', self::REVERSE_MAP);
        $this->renameStringColumn('syllabus_topics', 'bloom_level', self::REVERSE_MAP);
        $this->renameJsonKeys('tos_runs', 'bloom_distribution', self::REVERSE_MAP);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function renameStringColumn(string $table, string $column, array $map): void
    {
        foreach ($map as $oldValue => $newValue) {
            DB::table($table)
                ->where($column, $oldValue)
                ->update([$column => $newValue]);
        }
    }

    /**
     * @param  array<string, string>  $map
     */
    private function renameJsonKeys(string $table, string $column, array $map): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->each(function (object $row) use ($table, $column, $map): void {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $row->{$column}, true);
            if (! is_array($decoded)) {
                return;
            }

            $updated = [];
            foreach ($decoded as $key => $value) {
                $newKey = $map[$key] ?? $key;
                $updated[$newKey] = $value;
            }

            DB::table($table)->where('id', $row->id)->update([
                $column => json_encode($updated),
            ]);
        });
    }
};
