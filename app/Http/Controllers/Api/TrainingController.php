<?php

namespace App\Http\Controllers\Api;

use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Exceptions\RegistrationRefused;
use App\Http\Controllers\Controller;
use App\Models\TrainingAttendance;
use App\Models\TrainingCourse;
use App\Models\TrainingRegistration;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\EventRegistration;
use App\Support\CampaignContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chương trình huấn luyện offline (nhiều buổi) cho app.
 * Public (token không bắt buộc; có token app thì trả thêm "registered"):
 * - GET /trainings       : chương trình đang mở còn buổi CHƯA KẾT THÚC (hiện đến khi qua hết buổi cuối; `ongoing` = đang diễn ra),
 *                          buổi kế tiếp gần nhất trước; kèm lịch gọn (schedule).
 * - GET /trainings/{id}  : chi tiết + khối nội dung + mọi buổi (buổi 1 … n, địa điểm).
 * Cần đăng nhập:
 * - POST   /trainings/{id}/register : đăng ký cả chương trình (cần gói thành viên; phí > 0 → payment_required).
 * - DELETE /trainings/{id}/register : huỷ (trước buổi 1, chỉ đăng ký tự làm trên app).
 * - GET    /my/trainings            : chương trình đã đăng ký.
 * - POST   /trainings/checkin       : {code} — học viên quét QR điểm danh của chương trình (chỉ trong ngày có buổi).
 * Đã đăng ký → chi tiết có `ticket` (mã vé + nội dung QR để nhân viên quét, điểm danh từng buổi).
 */
class TrainingController extends Controller
{
    public function __construct(private readonly EventRegistration $registration) {}

    public function index(Request $request): JsonResponse
    {
        $user = OptionalAppUser::from($request);
        $courses = TrainingCourse::query()
            ->listed()
            ->with(['trainer:id,name', 'activeSessions.location'])
            ->withCount('registrations')
            ->get()
            ->sortBy(fn (TrainingCourse $c) => $c->activeSessions->first(fn ($s) => $s->ends_at->isFuture())?->starts_at)
            ->values();
        $registered = $this->registeredIds($user, $courses->pluck('id')->all());

        return response()->json([
            'data' => $courses->map(fn (TrainingCourse $c) => [
                ...$this->summary($c, $user, $registered),
                // Lịch gọn mọi buổi (thẻ trên Home hiện số buổi + dải ngày); chi tiết địa điểm ở GET /trainings/{id}
                'schedule' => $c->activeSessions->values()->map(fn (TrainingSession $s, int $i) => [
                    'number' => $i + 1,
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'ends_at' => $s->ends_at->toIso8601String(),
                    'state' => $s->state(),
                ])->all(),
            ]),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = OptionalAppUser::from($request);
        $course = TrainingCourse::query()
            ->where('status', TrainingCourseStatus::Published)
            ->with(['trainer:id,name', 'activeSessions.location'])
            ->withCount('registrations')
            ->find($id);

        if (! $course) {
            return response()->json(['message' => 'Chương trình huấn luyện không tồn tại hoặc đã đóng.'], 404);
        }

        return response()->json(['data' => $this->detail($course, $user, $this->registeredIds($user, [$course->id]))]);
    }

    public function register(Request $request, int $id): JsonResponse
    {
        $course = $this->published($id);

        try {
            $this->registration->registerTraining($request->user(), $course, RegistrationSource::App);
        } catch (RegistrationRefused $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json(['data' => $this->fresh($course, $request->user())], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $course = TrainingCourse::query()->findOrFail($id);

        try {
            $this->registration->cancelTraining($request->user(), $course);
        } catch (RegistrationRefused $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json(['data' => $this->fresh($course, $request->user())]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $registrations = $user->trainingRegistrations()
            ->with(['course' => fn ($q) => $q->with(['trainer:id,name', 'activeSessions.location'])->withCount('registrations')])
            ->latest()
            ->get()
            ->filter(fn ($r) => $r->course !== null);
        $ids = $registrations->pluck('training_course_id')->all();

        return response()->json([
            'data' => $registrations->map(fn ($r) => [
                ...$this->detail($r->course, $user, $ids, withBlocks: false),
                'registered_at' => $r->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    private function published(int $id): TrainingCourse
    {
        return TrainingCourse::query()->where('status', TrainingCourseStatus::Published)->findOrFail($id);
    }

    private function fresh(TrainingCourse $course, User $user): array
    {
        $course = TrainingCourse::query()->with(['trainer:id,name', 'activeSessions.location'])->withCount('registrations')->findOrFail($course->id);

        return $this->summary($course, $user, $this->registeredIds($user, [$course->id]));
    }

    /** @return list<int> id chương trình mà $user đã đăng ký (trong $ids) */
    private function registeredIds(?User $user, array $ids): array
    {
        return $user ? $user->trainingRegistrations()->whereIn('training_course_id', $ids)->pluck('training_course_id')->all() : [];
    }

    private function summary(TrainingCourse $c, ?User $user, array $registered): array
    {
        $sessions = $c->activeSessions;
        // Buổi kế tiếp = buổi chưa kết thúc gần nhất (có thể đang diễn ra)
        $next = $sessions->first(fn (TrainingSession $s) => $s->ends_at->isFuture());
        $count = (int) ($c->registrations_count ?? $c->registrations()->count());

        return [
            'id' => $c->id,
            'title' => $c->title,
            'summary' => $c->summary,
            'trainer' => $c->trainer ? ['id' => $c->trainer->id, 'name' => $c->trainer->name, 'avatar' => $c->trainer->avatarUrl()] : null,
            'price' => $c->price,
            'is_free' => $c->price === 0,          // miễn phí với học viên có gói thành viên
            'capacity' => $c->capacity,
            'seats_left' => $c->seatsLeft($count),
            'banner' => [
                'url' => $c->bannerUrl(),
                'width' => TrainingCourse::BANNER_WIDTH,
                'height' => TrainingCourse::BANNER_HEIGHT,
            ],
            'trailer' => $c->trailer(),
            'sessions_count' => $sessions->count(),
            'starts_at' => $sessions->first()?->starts_at->toIso8601String(),
            'ends_at' => $sessions->last()?->ends_at->toIso8601String(),
            'next_session' => $next ? $this->session($next, $sessions->search(fn ($s) => $s->id === $next->id) + 1) : null,
            'registration_open' => $c->isRegistrationOpen(),
            'ongoing' => $c->isOngoing(),
            'registered' => $user ? in_array($c->id, $registered, true) : null,
        ];
    }

    private function detail(TrainingCourse $c, ?User $user, array $registered, bool $withBlocks = true): array
    {
        return [
            ...$this->summary($c, $user, $registered),
            ...($withBlocks ? ['blocks' => CampaignContent::resolve($c->content)] : []),
            'sessions' => $c->activeSessions->values()->map(fn (TrainingSession $s, int $i) => $this->session($s, $i + 1))->all(),
            'ticket' => $user && in_array($c->id, $registered, true) ? $this->ticket($c, $user) : null,
        ];
    }

    /** Vé của học viên: mã + nội dung QR (nhân viên quét để điểm danh) + điểm danh từng buổi. */
    private function ticket(TrainingCourse $c, User $user): ?array
    {
        $r = TrainingRegistration::query()->where('training_course_id', $c->id)->where('user_id', $user->id)->with('attendances')->first();
        if (! $r) {
            return null;
        }
        if (! $r->ticket_code) {
            $r->forceFill(['ticket_code' => TrainingRegistration::newTicketCode()])->save();
        }
        $byS = $r->attendances->keyBy('training_session_id');

        return [
            'code' => $r->ticket_code,
            'qr' => $r->qrValue(),
            'holder' => $user->name,
            'registered_at' => $r->created_at->toIso8601String(),
            'attendance' => $c->activeSessions->values()->map(fn (TrainingSession $s, int $i) => [
                'session_id' => $s->id,
                'number' => $i + 1,
                'checked_in_at' => $byS->get($s->id)?->checked_in_at?->toIso8601String(),
                'method' => $byS->get($s->id)?->method,
            ])->all(),
        ];
    }

    /**
     * Học viên quét QR điểm danh của chương trình (in / chiếu ở lớp). QR chỉ có hiệu lực trong NGÀY có buổi (giờ VN):
     * ghi giờ đến cho buổi hôm đó. Đã điểm danh rồi → trả lại giờ cũ (already = true).
     */
    public function checkin(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:200']]);
        $token = str_starts_with($data['code'], TrainingCourse::CHECKIN_PREFIX)
            ? substr($data['code'], strlen(TrainingCourse::CHECKIN_PREFIX))
            : trim($data['code']);
        $course = TrainingCourse::query()->where('checkin_token', $token)->where('status', TrainingCourseStatus::Published)->first();
        if (! $course) {
            return response()->json(['message' => 'Mã QR không đúng. Hãy quét mã điểm danh của chương trình huấn luyện.', 'code' => 'invalid_code'], 422);
        }

        $user = $request->user();
        $registration = TrainingRegistration::query()->where('training_course_id', $course->id)->where('user_id', $user->id)->first();
        if (! $registration) {
            return response()->json(['message' => 'Bạn chưa đăng ký chương trình "'.$course->title.'".', 'code' => 'not_registered'], 403);
        }

        $session = $course->sessionOn();
        if (! $session) {
            return response()->json(['message' => 'Hôm nay "'.$course->title.'" không có buổi học. Mã điểm danh chỉ dùng được trong ngày có buổi.', 'code' => 'no_session_today'], 422);
        }

        $attendance = TrainingAttendance::query()->firstOrCreate(
            ['training_registration_id' => $registration->id, 'training_session_id' => $session->id],
            ['checked_in_at' => now(), 'method' => TrainingAttendance::SELF_SCAN],
        );
        $number = $course->sessionNumbers()[$session->id] ?? null;

        return response()->json(['data' => [
            'course_id' => $course->id,
            'course_title' => $course->title,
            'session_id' => $session->id,
            'number' => $number,
            'checked_in_at' => $attendance->checked_in_at->toIso8601String(),
            'already' => ! $attendance->wasRecentlyCreated,
        ]], $attendance->wasRecentlyCreated ? 201 : 200);
    }

    private function session(TrainingSession $s, int $number): array
    {
        return [
            'id' => $s->id,
            'number' => $number,
            'starts_at' => $s->starts_at->toIso8601String(),
            'ends_at' => $s->ends_at->toIso8601String(),
            'state' => $s->state(),
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
