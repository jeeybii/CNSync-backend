<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsFaculty
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::Faculty) {
            return response()->json([
                'message' => 'This action is restricted to faculty accounts.',
            ], 403);
        }

        return $next($request);
    }
}
