<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Services\AppSessionTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSessionController extends Controller
{
    /** Gợi ý chu kỳ heartbeat cho app (giây). */
    public const HEARTBEAT_SECONDS = 60;

    /**
     * POST /app-sessions/ping — không cần body; thông tin thiết bị lấy từ header X-Device-*.
     * App gọi khi mở app, khi quay lại từ nền, và mỗi HEARTBEAT_SECONDS khi đang mở.
     */
    public function ping(Request $request, AppSessionTracker $tracker): JsonResponse
    {
        [$session, $isNew] = $tracker->ping($request->user(), $request);

        return response()->json([
            'session_id' => $session->id,
            'new_session' => $isNew,
            'heartbeat_seconds' => self::HEARTBEAT_SECONDS,
            'idle_timeout_seconds' => AppSession::IDLE_MINUTES * 60,
        ], $isNew ? 201 : 200);
    }
}
