<?php

namespace App\Http\Controllers\Api;

use App\Enums\TrainingCourseStatus;
use App\Http\Controllers\Controller;
use App\Models\TrainingCourse;
use App\Models\TrainingSession;
use App\Support\CampaignContent;
use Illuminate\Http\JsonResponse;

/**
 * Khoá huấn luyện offline cho app (không cần đăng nhập).
 * - GET /trainings       : khoá đang mở còn ngày sắp diễn ra, ngày gần nhất trước.
 * - GET /trainings/{id}  : chi tiết + khối nội dung + các ngày sắp diễn ra (địa điểm, số chỗ).
 * Đăng ký / thanh toán: làm sau (cùng đợt mua khoá học) — khi đó thêm số chỗ còn lại.
 */
class TrainingController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = TrainingCourse::query()
            ->listed()
            ->with(['upcomingSessions.location'])
            ->get()
            ->sortBy(fn (TrainingCourse $c) => $c->upcomingSessions->first()?->starts_at)
            ->values();

        return response()->json([
            'data' => $courses->map(fn (TrainingCourse $c) => [
                ...$this->summary($c),
                'next_session' => ($next = $c->upcomingSessions->first()) ? $this->session($next) : null,
                'upcoming_sessions_count' => $c->upcomingSessions->count(),
            ]),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $course = TrainingCourse::query()
            ->where('status', TrainingCourseStatus::Published)
            ->with(['upcomingSessions.location'])
            ->find($id);

        if (! $course) {
            return response()->json(['message' => 'Khoá huấn luyện không tồn tại hoặc đã đóng.'], 404);
        }

        return response()->json([
            'data' => [
                ...$this->summary($course),
                'blocks' => CampaignContent::resolve($course->content),
                'sessions' => $course->upcomingSessions->map(fn (TrainingSession $s) => $this->session($s))->values(),
            ],
        ]);
    }

    private function summary(TrainingCourse $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'summary' => $c->summary,
            'trainer_name' => $c->trainer_name,
            'price' => $c->price,
            'banner' => [
                'url' => $c->bannerUrl(),
                'width' => TrainingCourse::BANNER_WIDTH,
                'height' => TrainingCourse::BANNER_HEIGHT,
            ],
        ];
    }

    private function session(TrainingSession $s): array
    {
        return [
            'id' => $s->id,
            'starts_at' => $s->starts_at->toIso8601String(),
            'ends_at' => $s->ends_at->toIso8601String(),
            'capacity' => $s->capacity,
            'note' => $s->note,
            'location' => $s->location ? [
                'label' => $s->location->label,
                'address' => $s->location->address,
                'map_url' => $s->location->map_url,
                'note' => $s->location->note,
            ] : null,
        ];
    }
}
