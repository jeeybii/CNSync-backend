<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'last_login_at', 'created_at'])
            ->orderByDesc('created_at');

        if (! blank($validated['search'] ?? null)) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        if (! blank($validated['role'] ?? null)) {
            $query->where('role', $validated['role']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginated = $query->paginate($perPage);

        return response()->json(['data' => $paginated]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user->only(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'last_login_at', 'created_at', 'updated_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'string', Rule::in(['faculty', 'student'])],
        ]);

        $user = User::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => match ($validated['role']) {
                'student' => UserRole::Student,
                default => UserRole::Faculty,
            },
            'status' => 'active',
        ]);

        return response()->json([
            'data' => $user->only(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'last_login_at', 'created_at']),
        ], 201);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->role === UserRole::Admin) {
            return response()->json(['message' => 'Admin accounts cannot be deleted.'], 403);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    public function updateProfile(Request $request, User $user): JsonResponse
    {
        if ($user->role === UserRole::Admin) {
            return response()->json(['message' => 'Admin profiles cannot be edited here.'], 403);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::in(['faculty', 'student'])],
        ]);

        $user->update($validated);

        return response()->json([
            'data' => $user->fresh(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'last_login_at', 'created_at']),
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        if ($user->role === UserRole::Admin) {
            return response()->json(['message' => 'Admin account status cannot be changed.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $user->update(['status' => $validated['status']]);

        return response()->json([
            'data' => $user->only(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'last_login_at', 'created_at']),
        ]);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => ['required', Password::defaults()],
        ]);

        $user->update(['password' => $request->input('password')]);

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
