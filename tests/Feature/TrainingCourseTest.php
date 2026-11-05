<?php

namespace Tests\Feature;

use App\Enums\MembershipSource;
use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TrainingCourses\Pages\CreateTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\EditTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\ListTrainingCourses;
use App\Filament\Resources\TrainingCourses\Pages\ManageTrainingRegistrations;
use App\Filament\Resources\TrainingCourses\Pages\ManageTrainingSessions;
use App\Filament\Resources\TrainingLocations\Pages\ManageTrainingLocations;
use App\Models\TrainingCourse;
use App\Models\TrainingLocation;
use App\Models\TrainingRegistration;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Chương trình huấn luyện offline: nhiều buổi, đăng ký cả chương trình, số tối đa theo chương trình, trailer. */
class TrainingCourseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private TrainingLocation $cafeA;

    private TrainingLocation $cafeB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true, 'name' => 'Cô Lan']);
        $this->cafeA = TrainingLocation::create(['label' => 'Cafe A', 'address' => '12 Nguyễn Huệ, Q.1']);
        $this->cafeB = TrainingLocation::create(['label' => 'Cafe B', 'address' => '88 XVNT, Bình Thạnh', 'map_url' => 'https://maps.google.com/?q=b']);
    }

    private function course(array $attributes = []): TrainingCourse
    {
        return TrainingCourse::create([
            'title' => 'Quản lý',
            'banner_path' => 'trainings/banners/x.png',
            'price' => 200_000,
            'capacity' => 20,
            'status' => TrainingCourseStatus::Published,
            ...$attributes,
        ]);
    }

    /** Giờ Việt Nam → UTC */
    private function vn(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh')->utc();
    }

    private function addSession(TrainingCourse $course, TrainingLocation $location, string $date, array $extra = []): TrainingSession
    {
        return TrainingSession::create([
            'training_course_id' => $course->id,
            'training_location_id' => $location->id,
            'starts_at' => $this->vn("{$date} 18:00"),
            'ends_at' => $this->vn("{$date} 21:00"),
            ...$extra,
        ]);
    }

    private function student(bool $member = true, string $name = 'An'): User
    {
        $user = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'name' => $name]);
        if ($member) {
            app(MembershipService::class)->grant($user, MembershipSource::Admin);
        }

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('app')->plainTextToken;
    }

    /** Gọi API bằng token của $user (quên user đã đăng nhập ở request trước / phiên CMS). */
    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token($user));
    }

    public function test_cms_create_program_then_schedule_numbered_sessions(): void
    {
        $this->actingAs($this->admin);
        $this->get(ListTrainingCourses::getUrl())->assertOk()->assertSee('Chương trình huấn luyện');

        $undo = Builder::fake();
        Livewire::test(CreateTrainingCourse::class)
            ->fillForm([
                'title' => 'Quản lý đội nhóm',
                'summary' => '3 buổi',
                'price' => 1500000,
                'capacity' => 15,
                'trainer_id' => $this->teacher->id,
                'status' => TrainingCourseStatus::Published->value,
                'banner_path' => UploadedFile::fake()->image('banner.png', 1280, 720),
                'trailer_type' => 'youtube',
                'trailer_youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'content' => [['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => ['Giao việc']]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $course = TrainingCourse::sole();
        $this->assertSame([1_500_000, 15], [$course->price, $course->capacity]);
        $this->assertTrue($course->createdBy->is($this->admin));
        $this->assertSame(['type' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ'], $course->trailer());
        Storage::disk('public')->assertExists($course->banner_path);

        // Buổi 1 ở Cafe A; buổi 2 lấy sẵn địa điểm + giờ của buổi gần nhất
        $page = Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()]);
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-15', 'start_time' => '18:00', 'end_time' => '21:00', 'training_location_id' => $this->cafeA->id,
        ])->assertHasNoFormErrors();
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->mountAction(TestAction::make('create')->table())
            ->assertSchemaStateSet(['training_location_id' => $this->cafeA->id, 'start_time' => '18:00', 'end_time' => '21:00', 'date' => null]);
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-20', 'start_time' => '08:30', 'end_time' => '11:30', 'training_location_id' => $this->cafeB->id, 'note' => 'Có ăn sáng',
        ])->assertHasNoFormErrors();
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-22', 'start_time' => '18:00', 'end_time' => '17:00', 'training_location_id' => $this->cafeB->id,
        ])->assertHasFormErrors(['end_time']);

        [$first, $second] = $course->sessions()->get()->all();
        $this->assertSame('2026-11-15 11:00:00', $first->starts_at->utc()->format('Y-m-d H:i:s')); // 18:00 giờ VN
        $this->assertSame('CN, 15/11/2026', $first->dateLabel());
        $this->assertTrue($second->location->is($this->cafeB));

        // Thêm buổi tương tự: chỉ chọn ngày; buổi trước ngày 15/11 thành buổi 1
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('repeat')->table($first), ['date' => '2026-11-10'])
            ->assertHasNoActionErrors();
        $earliest = $course->sessions()->first();
        $this->assertSame('2026-11-10 18:00', $earliest->starts_at->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'));
        $this->assertSame([$earliest->id => 1, $first->id => 2, $second->id => 3], $course->sessionNumbers());

        // Huỷ buổi → đánh số lại
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('cancel')->table($earliest));
        $this->assertSame([$first->id => 1, $second->id => 2], $course->fresh()->sessionNumbers());
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->assertSee('Buổi 1')->assertSee('Buổi 2')->assertDontSee('Buổi 3')->assertSee('Tối đa 15 học viên');

        Livewire::test(ListTrainingCourses::class)->assertSee('2 buổi · từ CN, 15/11/2026')->assertSee('0 / 15');

        // Đổi trailer sang file video → bỏ link YouTube
        $undo = Builder::fake();
        Livewire::test(EditTrainingCourse::class, ['record' => $course->getRouteKey()])
            ->assertSchemaStateSet(['trailer_type' => 'youtube'])
            ->fillForm(['trailer_type' => 'file', 'trailer_video_path' => UploadedFile::fake()->create('trailer.mp4', 2048, 'video/mp4')])
            ->call('save')
            ->assertHasNoFormErrors();
        $undo();
        $course->refresh();
        $this->assertNull($course->trailer_youtube_url);
        $this->assertSame('file', $course->trailer()['type']);
        Storage::disk('public')->assertExists($course->trailer_video_path);

        // Link YouTube sai → báo lỗi; chọn "Không có" → xoá file trailer khỏi storage
        $video = $course->trailer_video_path;
        $undo = Builder::fake();
        Livewire::test(EditTrainingCourse::class, ['record' => $course->getRouteKey()])
            ->fillForm(['trailer_type' => 'youtube', 'trailer_youtube_url' => 'https://vimeo.com/123'])
            ->call('save')
            ->assertHasFormErrors(['trailer_youtube_url']);
        Livewire::test(EditTrainingCourse::class, ['record' => $course->getRouteKey()])
            ->fillForm(['trailer_type' => 'none'])
            ->call('save')
            ->assertHasNoFormErrors();
        $undo();
        $this->assertNull($course->fresh()->trailer());
        Storage::disk('public')->assertMissing($video);
    }

    public function test_duplicate_copies_info_without_sessions_or_registrants(): void
    {
        $this->actingAs($this->admin);
        $course = $this->course(['title' => 'Quản lý', 'capacity' => 12, 'trailer_youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->addSession($course, $this->cafeA, '2026-12-01');
        TrainingRegistration::create(['training_course_id' => $course->id, 'user_id' => $this->student()->id, 'source' => RegistrationSource::Admin]);

        Livewire::test(ListTrainingCourses::class)->callAction(TestAction::make('replicate')->table($course));

        $copy = TrainingCourse::where('title', 'Quản lý (bản sao)')->sole();
        $this->assertSame(TrainingCourseStatus::Draft, $copy->status);
        $this->assertSame(12, $copy->capacity);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $copy->trailer_youtube_url);
        $this->assertSame(0, $copy->sessions()->count());
        $this->assertSame(0, $copy->registrations()->count());
    }

    public function test_registration_rules_membership_payment_capacity(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Ho_Chi_Minh'));
        $free = $this->course(['title' => 'Miễn phí', 'price' => 0, 'capacity' => 2]);
        $this->addSession($free, $this->cafeA, '2026-11-15');
        $this->addSession($free, $this->cafeB, '2026-11-20');
        $paid = $this->course(['title' => 'Có phí', 'price' => 500_000]);
        $this->addSession($paid, $this->cafeA, '2026-11-16');

        $this->postJson("/v1/trainings/{$free->id}/register")->assertUnauthorized();

        // Chưa có gói → membership_required
        $nonMember = $this->student(false, 'Bình');
        $this->api($nonMember)->postJson("/v1/trainings/{$free->id}/register")
            ->assertForbidden()->assertJsonPath('code', 'membership_required');

        // Có gói, chương trình có phí → payment_required
        $a = $this->student(true, 'An');
        $this->api($a)->postJson("/v1/trainings/{$paid->id}/register")
            ->assertStatus(402)->assertJsonPath('code', 'payment_required');

        // Miễn phí + có gói → đăng ký cả chương trình; gọi lại không lỗi
        $this->api($a)->postJson("/v1/trainings/{$free->id}/register")->assertCreated()
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.seats_left', 1)
            ->assertJsonPath('data.sessions_count', 2);
        $this->api($a)->postJson("/v1/trainings/{$free->id}/register")->assertCreated();
        $this->assertSame(1, $free->registrations()->count());

        // Hết chỗ (tối đa 2 cho cả chương trình)
        $this->api($this->student(true, 'Chi'))->postJson("/v1/trainings/{$free->id}/register")->assertCreated();
        $this->api($this->student(true, 'Dũng'))->postJson("/v1/trainings/{$free->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'full');

        // Danh sách của tôi
        $this->api($a)->getJson('/v1/my/trainings')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Miễn phí')
            ->assertJsonPath('data.0.registered', true)
            ->assertJsonPath('data.0.sessions.1.number', 2)
            ->assertJsonPath('data.0.sessions.1.location.label', 'Cafe B');

        // Huỷ trước buổi 1 → được
        $this->api($a)->deleteJson("/v1/trainings/{$free->id}/register")->assertOk()
            ->assertJsonPath('data.registered', false)->assertJsonPath('data.seats_left', 1);

        // Sau khi buổi 1 bắt đầu: không đăng ký / huỷ trên app được nữa
        $this->api($a)->postJson("/v1/trainings/{$free->id}/register")->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 18:30', 'Asia/Ho_Chi_Minh'));
        $this->api($a)->deleteJson("/v1/trainings/{$free->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'cancel_closed');
        $this->api($this->student(true, 'Em'))->postJson("/v1/trainings/{$free->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'registration_closed');
    }

    public function test_admin_adds_and_removes_registrants(): void
    {
        $this->actingAs($this->admin);
        $course = $this->course(['price' => 1_500_000, 'capacity' => 1]);
        $this->addSession($course, $this->cafeA, now('Asia/Ho_Chi_Minh')->addDays(5)->toDateString());
        $member = $this->student(true, 'Hà');
        $nonMember = $this->student(false, 'Khoa');

        // Chưa có gói → không thêm được
        Livewire::test(ManageTrainingRegistrations::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('addRegistrant')->table(), ['user_id' => $nonMember->id]);
        $this->assertSame(0, $course->registrations()->count());

        // Có gói, chương trình có phí → admin thêm được (VD đã chuyển khoản)
        Livewire::test(ManageTrainingRegistrations::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('addRegistrant')->table(), ['user_id' => $member->id, 'note' => 'Đã chuyển khoản'])
            ->assertHasNoActionErrors();
        $registration = $course->registrations()->sole();
        $this->assertSame(RegistrationSource::Admin, $registration->source);
        $this->assertTrue($registration->createdBy->is($this->admin));
        $this->assertSame('Đã chuyển khoản', $registration->note);

        // Đủ chỗ (tối đa 1) → không thêm được người thứ 2
        Livewire::test(ManageTrainingRegistrations::class, ['record' => $course->getRouteKey()])
            ->assertSee('1 / 1 học viên (đã đủ)')
            ->assertCanSeeTableRecords([$registration])
            ->assertSee('Hà')
            ->callAction(TestAction::make('addRegistrant')->table(), ['user_id' => $this->student(true, 'Lâm')->id]);
        $this->assertSame(1, $course->registrations()->count());

        // Học viên không tự huỷ được đăng ký do admin thêm
        $this->api($member)->deleteJson("/v1/trainings/{$course->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'cancel_not_allowed');

        // Admin huỷ
        $this->app['auth']->shouldUse('web'); // request API trước đã chuyển guard mặc định sang sanctum
        $this->flushHeaders()->actingAs($this->admin);
        Livewire::test(ManageTrainingRegistrations::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('delete')->table($registration));
        $this->assertSame(0, $course->registrations()->count());
    }

    public function test_locations_saved_and_delete_guarded(): void
    {
        $this->actingAs($this->admin);
        $this->addSession($this->course(), $this->cafeA, '2026-11-15');

        $this->get(ManageTrainingLocations::getUrl())->assertOk()->assertSee('Cafe A')->assertSee('Mở bản đồ');
        Livewire::test(ManageTrainingLocations::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->cafeA))
            ->callAction(TestAction::make('delete')->table($this->cafeB));
        $this->assertSoftDeleted($this->cafeB);

        Livewire::test(ManageTrainingLocations::class)
            ->callAction('create', ['label' => 'Cafe C', 'address' => '1 Lê Lợi', 'map_url' => 'không phải link'])
            ->assertHasActionErrors(['map_url']);
    }

    public function test_api_lists_published_programs_with_numbered_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Ho_Chi_Minh'));
        $later = $this->course(['title' => 'Sau']);
        $this->addSession($later, $this->cafeA, '2026-11-25');

        $soon = $this->course(['title' => 'Sớm', 'price' => 0, 'capacity' => 10, 'content' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Giới thiệu']],
            ['type' => 'promo_code', 'data' => ['promo_code_id' => 1]], // không dùng ở chương trình huấn luyện → bỏ
        ]]);
        $s1 = $this->addSession($soon, $this->cafeA, '2026-11-15');
        $this->addSession($soon, $this->cafeB, '2026-11-18', ['cancelled_at' => now()]); // đã huỷ → không tính
        $s2 = $this->addSession($soon, $this->cafeB, '2026-11-20');

        $this->addSession($this->course(['title' => 'Nháp', 'status' => TrainingCourseStatus::Draft]), $this->cafeA, '2026-11-10');
        $this->addSession($this->course(['title' => 'Hết lịch']), $this->cafeA, '2026-10-01');

        $this->getJson('/v1/trainings')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Sớm')
            ->assertJsonPath('data.0.price', 0)
            ->assertJsonPath('data.0.is_free', true)
            ->assertJsonPath('data.0.capacity', 10)
            ->assertJsonPath('data.0.seats_left', 10)
            ->assertJsonPath('data.0.banner.width', 1280)
            ->assertJsonPath('data.0.sessions_count', 2)
            ->assertJsonCount(2, 'data.0.schedule')
            ->assertJsonPath('data.0.schedule.1.number', 2)
            ->assertJsonPath('data.0.next_session.id', $s1->id)
            ->assertJsonPath('data.0.next_session.number', 1)
            ->assertJsonPath('data.0.next_session.location.label', 'Cafe A')
            ->assertJsonPath('data.0.registration_open', true)
            ->assertJsonPath('data.0.registered', null)
            ->assertJsonPath('data.0.trailer', null)
            ->assertJsonPath('data.1.title', 'Sau');

        $this->getJson("/v1/trainings/{$soon->id}")->assertOk()
            ->assertJsonPath('data.blocks', [['type' => 'paragraph', 'text' => 'Giới thiệu']])
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.sessions.1.id', $s2->id)
            ->assertJsonPath('data.sessions.1.number', 2)
            ->assertJsonPath('data.sessions.1.starts_at', '2026-11-20T11:00:00+00:00')
            ->assertJsonPath('data.sessions.1.location.map_url', 'https://maps.google.com/?q=b');

        // Có token app → "registered"
        $student = $this->student();
        TrainingRegistration::create(['training_course_id' => $soon->id, 'user_id' => $student->id, 'source' => RegistrationSource::App]);
        $this->api($student)->getJson("/v1/trainings/{$soon->id}")
            ->assertJsonPath('data.registered', true)->assertJsonPath('data.seats_left', 9);
        $this->api($student)->getJson('/v1/trainings')
            ->assertJsonPath('data.0.registered', true)->assertJsonPath('data.1.registered', false);

        $draft = TrainingCourse::where('title', 'Nháp')->sole();
        $this->getJson("/v1/trainings/{$draft->id}")->assertNotFound();
    }

    /** Link ảnh theo domain + cổng đang gọi (không theo APP_URL) — tránh ô ảnh CMS kẹt "Loading" khi khác origin. */
    public function test_public_file_urls_follow_request_host(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->addSession($this->course(['trailer_video_path' => 'trainings/trailers/t.mp4']), $this->cafeA, now('Asia/Ho_Chi_Minh')->addDays(5)->toDateString());

        $this->getJson('http://192.168.1.20:8000/v1/trainings')
            ->assertOk()
            ->assertJsonPath('data.0.banner.url', 'http://192.168.1.20:8000/storage/trainings/banners/x.png')
            ->assertJsonPath('data.0.trailer', ['type' => 'file', 'url' => 'http://192.168.1.20:8000/storage/trainings/trailers/t.mp4']);
    }

    public function test_instructor_sees_only_own_programs_read_only(): void
    {
        $mine = $this->course(['title' => 'Chương trình của tôi', 'trainer_id' => $this->teacher->id]);
        $session = $this->addSession($mine, $this->cafeA, now('Asia/Ho_Chi_Minh')->addDays(3)->toDateString());
        $registration = TrainingRegistration::create(['training_course_id' => $mine->id, 'user_id' => $this->student(true, 'Mai')->id, 'source' => RegistrationSource::App]);
        $other = $this->course(['title' => 'Chương trình người khác']);

        $this->actingAs($this->teacher);
        Livewire::test(ListTrainingCourses::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden('create');

        $this->get(EditTrainingCourse::getUrl(['record' => $mine]))->assertForbidden();
        $this->get(ManageTrainingSessions::getUrl(['record' => $other]))->assertNotFound(); // ngoài phạm vi của giảng viên
        Livewire::test(ManageTrainingSessions::class, ['record' => $mine->getRouteKey()])
            ->assertCanSeeTableRecords([$session])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($session))
            ->assertActionHidden(TestAction::make('repeat')->table($session))
            ->assertActionHidden(TestAction::make('cancel')->table($session))
            ->assertActionHidden(TestAction::make('delete')->table($session));

        // Người đăng ký: xem được, không thêm / huỷ
        Livewire::test(ManageTrainingRegistrations::class, ['record' => $mine->getRouteKey()])
            ->assertCanSeeTableRecords([$registration])
            ->assertActionHidden(TestAction::make('addRegistrant')->table())
            ->assertActionHidden(TestAction::make('delete')->table($registration));

        $this->get(ManageTrainingLocations::getUrl())->assertForbidden();

        $this->getJson("/v1/trainings/{$mine->id}")->assertJsonPath('data.trainer', ['id' => $this->teacher->id, 'name' => 'Cô Lan']);
    }

    /** Bỏ / thay ảnh rồi lưu, hoặc xoá chương trình → file trên storage bị xoá (trừ khi bản ghi khác còn dùng). */
    public function test_removed_images_are_deleted_from_storage(): void
    {
        $disk = Storage::disk('public');
        foreach (['trainings/banners/a.png', 'trainings/banners/b.png', 'trainings/content/c.png', 'trainings/content/d.png', 'trainings/trailers/t.mp4'] as $path) {
            $disk->put($path, 'x');
        }

        $course = $this->course([
            'banner_path' => 'trainings/banners/a.png',
            'trailer_video_path' => 'trainings/trailers/t.mp4',
            'content' => [
                ['type' => 'image', 'data' => ['image' => 'trainings/content/c.png']],
                ['type' => 'image', 'data' => ['image' => ['uuid-1' => 'trainings/content/d.png']]],
            ],
        ]);

        // Thay banner → xoá banner cũ; bỏ khối ảnh c → xoá c
        $course->update([
            'banner_path' => 'trainings/banners/b.png',
            'content' => [['type' => 'image', 'data' => ['image' => ['uuid-1' => 'trainings/content/d.png']]]],
        ]);
        $disk->assertMissing('trainings/banners/a.png');
        $disk->assertMissing('trainings/content/c.png');
        $disk->assertExists('trainings/banners/b.png');
        $disk->assertExists('trainings/content/d.png');

        // Bản nhân bản dùng chung banner + ảnh + trailer → xoá bản gốc vẫn giữ file
        $copy = $course->replicate();
        $copy->save();
        $course->delete();
        $disk->assertExists('trainings/banners/b.png');
        $disk->assertExists('trainings/content/d.png');
        $disk->assertExists('trainings/trailers/t.mp4');

        // Xoá nốt bản còn lại → xoá file
        $copy->delete();
        $disk->assertMissing('trainings/banners/b.png');
        $disk->assertMissing('trainings/content/d.png');
        $disk->assertMissing('trainings/trailers/t.mp4');
    }

    /** Khoá học có thùng rác: xoá tạm vẫn giữ ảnh bìa (để khôi phục), xoá vĩnh viễn mới xoá file. */
    public function test_course_cover_kept_in_trash_deleted_on_force_delete(): void
    {
        Storage::disk('public')->put('courses/covers/a.png', 'x');
        $course = \App\Models\Course::create(['title' => 'Khoá', 'instructor_id' => $this->admin->id, 'price' => 0, 'cover_image' => 'courses/covers/a.png']);

        $course->delete();
        Storage::disk('public')->assertExists('courses/covers/a.png');

        $course->forceDelete();
        Storage::disk('public')->assertMissing('courses/covers/a.png');
    }
}
