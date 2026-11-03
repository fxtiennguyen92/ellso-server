<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerEvent;
use Illuminate\Http\JsonResponse;

/**
 * Sự kiện ngoài lề cho app (không cần đăng nhập) — chỉ là tin, không đăng ký trong app.
 * - GET /partner-events       : sự kiện đang hiển thị, đúng thứ tự trên CMS.
 * - GET /partner-events/{id}  : một sự kiện (ẩn / hết thời gian hiển thị → 404).
 */
class PartnerEventController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PartnerEvent::query()->visible()->limit(50)->get()
                ->map(fn (PartnerEvent $e) => $this->item($e))->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $event = PartnerEvent::query()->visible()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Sự kiện không tồn tại hoặc đã kết thúc.'], 404);
        }

        return response()->json(['data' => $this->item($event)]);
    }

    private function item(PartnerEvent $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'image' => [
                'url' => $e->imageUrl(),
                'width' => PartnerEvent::IMAGE_WIDTH,
                'height' => PartnerEvent::IMAGE_HEIGHT,
            ],
            'time_text' => $e->time_text,
            'location' => $e->location,
            'description' => $e->description,
        ];
    }
}
