<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /** GET /app-config — cấu hình hiển thị cho app (public). */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'home_tagline' => SystemSetting::homeTagline(),
        ]]);
    }
}
