<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => SystemSetting::all()]);
    }

    public function update(Request $request, SystemSetting $systemSetting): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['required'],
        ]);

        $systemSetting->update(['value' => $validated['value']]);

        return response()->json(['data' => $systemSetting->fresh()]);
    }
}
