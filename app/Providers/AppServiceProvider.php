<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Project;
use App\Models\QuestionRun;
use App\Models\TosRun;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('project', function (string $value) {
            return Project::query()
                ->whereKey($value)
                ->where('user_id', auth()->id())
                ->firstOrFail();
        });

        Route::bind('document', function (string $value) {
            $project = request()->route('project');
            if (! $project instanceof Project) {
                abort(404);
            }

            return Document::query()
                ->whereKey($value)
                ->where('project_id', $project->id)
                ->firstOrFail();
        });

        Route::bind('tosRun', function (string $value) {
            $project = request()->route('project');
            if (! $project instanceof Project) {
                abort(404);
            }

            return TosRun::query()
                ->whereKey($value)
                ->where('project_id', $project->id)
                ->firstOrFail();
        });

        Route::bind('questionRun', function (string $value) {
            $project = request()->route('project');
            if (! $project instanceof Project) {
                abort(404);
            }

            return QuestionRun::query()
                ->whereKey($value)
                ->where('project_id', $project->id)
                ->firstOrFail();
        });
    }
}
