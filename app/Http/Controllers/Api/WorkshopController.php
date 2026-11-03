<?php

namespace App\Http\Controllers\Api;

use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Exceptions\RegistrationRefused;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Services\EventRegistration;
use App\Support\CampaignContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workshop online cho app.
 * Public (token không bắt buộc; có token app thì trả thêm "registered" + link họp):
 * - GET /workshops       : workshop đang mở còn buổi sắp diễn ra, buổi gần nhất trước.
 * - GET /workshops/{id}  : chi tiết + khối nội dung + các buổi chưa kết thúc (chỗ còn lại, link họp nếu được xem).
 * Cần đăng nhập:
 * - POST   /workshop-sessions/{id}/register : đăng ký một buổi (cần gói thành viên; phí > 0 → payment_required).
 * - DELETE /workshop-sessions/{id}/register : huỷ (trước giờ bắt đầu, chỉ đăng ký tự làm trên app).
 * - GET    /my/workshops                    : các buổi đã đăng ký (kèm link họp khi tới giờ).
 *
 * Link họp: chỉ người đã đăng ký, từ 30 phút trước giờ bắt đầu đến khi buổi kết thúc; ngoài khoảng đó null
 * + link_available_at.
 */
class WorkshopController extends Controller
{
    public function __construct(private readonly EventRegistration $registration) {}

    public function index(Request $request): JsonResponse
    {
        $workshops = Workshop::query()
            ->listed()
            ->with(['trainer:id,name', 'upcomingSessions'])
            ->get()
            ->sortBy(fn (Workshop $w) => $w->upcomingSessions->first()?->starts_at)
            ->values();

        return response()->json([
            'data' => $workshops->map(fn (Workshop $w) => [
                ...$this->summary($w),
                'next_session' => ($next = $w->upcomingSessions->first()) ? $this->sessionTime($next) : null,
                'upcoming_sessions_count' => $w->upcomingSessions->count(),
            ]),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = OptionalAppUser::from($request);
        $workshop = Workshop::query()
            ->where('status', TrainingCourseStatus::Published)
            ->with(['trainer:id,name', 'sessions' => fn ($q) => $q->notEnded()->withCount('registrations')])
            ->find($id);

        if (! $workshop) {
            return response()->json(['message' => 'Workshop không tồn tại hoặc đã đóng.'], 404);
        }

        $registered = $this->registeredIds($user, $workshop->sessions->pluck('id')->all());

        return response()->json([
            'data' => [
                ...$this->summary($workshop),
                'blocks' => CampaignContent::resolve($workshop->content),
                'sessions' => $workshop->sessions->map(fn (WorkshopSession $s) => $this->session($s, $workshop, $user, $registered))->values(),
            ],
        ]);
    }

    public function register(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkshopSession::query()->with('workshop')->findOrFail($sessionId);

        try {
            $this->registration->registerWorkshop($request->user(), $session, RegistrationSource::App);
        } catch (RegistrationRefused $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json(['data' => $this->freshSession($session, $request->user())], 201);
    }

    public function cancel(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkshopSession::query()->with('workshop')->findOrFail($sessionId);

        try {
            $this->registration->cancelWorkshop($request->user(), $session);
        } catch (RegistrationRefused $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json(['data' => $this->freshSession($session, $request->user())]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $registrations = $user->workshopRegistrations()
            ->with(['session' => fn ($q) => $q->withCount('registrations')->with('workshop.trainer:id,name')])
            ->get()
            ->filter(fn (WorkshopRegistration $r) => $r->session?->workshop !== null)
            // Buổi chưa kết thúc trước (gần nhất trước), rồi buổi đã qua (mới nhất trước)
            ->sortBy(fn (WorkshopRegistration $r) => $r->session->ends_at->isFuture()
                ? '0' . $r->session->starts_at->format('YmdHis')
                : '1' . (99999999999999 - (int) $r->session->starts_at->format('YmdHis')))
            ->values();
        $ids = $registrations->pluck('workshop_session_id')->all();

        return response()->json([
            'data' => $registrations->map(fn (WorkshopRegistration $r) => [
                ...$this->session($r->session, $r->session->workshop, $user, $ids),
                'registered_at' => $r->created_at->toIso8601String(),
                'workshop' => $this->summary($r->session->workshop),
            ])->all(),
        ]);
    }

    private function freshSession(WorkshopSession $session, User $user): array
    {
        $session = WorkshopSession::query()->withCount('registrations')->with('workshop')->findOrFail($session->id);

        return $this->session($session, $session->workshop, $user, $this->registeredIds($user, [$session->id]));
    }

    /** @return list<int> id buổi mà $user đã đăng ký (trong $ids) */
    private function registeredIds(?User $user, array $ids): array
    {
        return $user ? $user->workshopRegistrations()->whereIn('workshop_session_id', $ids)->pluck('workshop_session_id')->all() : [];
    }

    private function summary(Workshop $w): array
    {
        return [
            'id' => $w->id,
            'title' => $w->title,
            'summary' => $w->summary,
            'trainer' => $w->trainer ? ['id' => $w->trainer->id, 'name' => $w->trainer->name] : null,
            'price' => $w->price,
            'is_free' => $w->price === 0,          // miễn phí với học viên có gói thành viên
            'capacity' => $w->capacity,            // mỗi buổi; null = không giới hạn
            'online' => true,
            'banner' => [
                'url' => $w->bannerUrl(),
                'width' => Workshop::BANNER_WIDTH,
                'height' => Workshop::BANNER_HEIGHT,
            ],
        ];
    }

    private function sessionTime(WorkshopSession $s): array
    {
        return [
            'id' => $s->id,
            'starts_at' => $s->starts_at->toIso8601String(),
            'ends_at' => $s->ends_at->toIso8601String(),
        ];
    }

    private function session(WorkshopSession $s, Workshop $w, ?User $user, array $registered): array
    {
        $isRegistered = $user ? in_array($s->id, $registered, true) : null;
        $seatsLeft = $s->seatsLeft($w->capacity, (int) ($s->registrations_count ?? $s->registrations()->count()));

        return [
            ...$this->sessionTime($s),
            'state' => $s->state(),
            'note' => $s->note,
            'seats_left' => $seatsLeft,              // null = không giới hạn
            'full' => $seatsLeft === 0,
            'registration_open' => $s->isRegistrationOpen() && $w->status === TrainingCourseStatus::Published,
            'registered' => $isRegistered,
            'meeting_url' => $isRegistered && $s->isLinkOpen() ? $s->meeting_url : null,
            'link_available_at' => $isRegistered ? $s->linkAvailableAt()->toIso8601String() : null,
        ];
    }
}
