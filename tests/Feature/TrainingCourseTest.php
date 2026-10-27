<?php

namespace Tests\Feature;

use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TrainingCourses\Pages\CreateTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\EditTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\ListTrainingCourses;
use App\Filament\Resources\TrainingCourses\Pages\ManageTrainingSessions;
use App\Filament\Resources\TrainingLocations\Pages\ManageTrainingLocations;
use App\Models\TrainingCourse;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

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
            'status' => TrainingCourseStatus::Published,
            ...$attributes,
        ]);
    }

    /** Giờ Việt Nam → UTC */
    private function vn(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh')->utc();
    }

    private function addSession(TrainingCourse $course, TrainingLocation $location, string $date, int $capacity, array $extra = []): TrainingSession
    {
        return TrainingSession::create([
            'training_course_id' => $course->id,
            'training_location_id' => $location->id,
            'starts_at' => $this->vn("{$date} 18:00"),
            'ends_at' => $this->vn("{$date} 21:00"),
            'capacity' => $capacity,
            ...$extra,
        ]);
    }

    public function test_cms_create_course_then_schedule_sessions(): void
    {
        $this->actingAs($this->admin);
        $this->get(ListTrainingCourses::getUrl())->assertOk();

        $undo = Builder::fake();
        Livewire::test(CreateTrainingCourse::class)
            ->fillForm([
                'title' => 'Quản lý đội nhóm',
                'summary' => 'Buổi 3 giờ',
                'price' => 200000,
                'trainer_id' => $this->teacher->id,
                'status' => TrainingCourseStatus::Published->value,
                'banner_path' => UploadedFile::fake()->image('banner.png', 1280, 720),
                'content' => [['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => ['Giao việc']]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $course = TrainingCourse::sole();
        $this->assertSame(200_000, $course->price);
        $this->assertTrue($course->createdBy->is($this->admin));
        $this->assertTrue($course->trainer->is($this->teacher));
        Storage::disk('public')->assertExists($course->banner_path);

        // Ngày 15/11 ở Cafe A tối đa 10; 20/11 ở Cafe B tối đa 20
        $page = Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()]);
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-15', 'start_time' => '18:00', 'end_time' => '21:00',
            'training_location_id' => $this->cafeA->id, 'capacity' => 10,
        ])->assertHasNoFormErrors();
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-20', 'start_time' => '08:30', 'end_time' => '11:30',
            'training_location_id' => $this->cafeB->id, 'capacity' => 20, 'note' => 'Có ăn sáng',
        ])->assertHasNoFormErrors();
        // Giờ kết thúc trước giờ bắt đầu
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-22', 'start_time' => '18:00', 'end_time' => '17:00',
            'training_location_id' => $this->cafeB->id, 'capacity' => 5,
        ])->assertHasFormErrors(['end_time']);

        [$first, $second] = $course->sessions()->get()->all();
        $this->assertSame('2026-11-15 11:00:00', $first->starts_at->utc()->format('Y-m-d H:i:s')); // 18:00 giờ VN
        $this->assertSame('CN, 15/11/2026', $first->dateLabel());
        $this->assertSame('18:00 – 21:00', $first->timeLabel());
        $this->assertSame([10, 20], [$first->capacity, $second->capacity]);
        $this->assertTrue($second->location->is($this->cafeB));

        // Sửa: form nhận ngày giờ theo giờ VN
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->mountAction(TestAction::make('edit')->table($second))
            ->assertSchemaStateSet(['date' => '2026-11-20', 'start_time' => '08:30', 'end_time' => '11:30', 'capacity' => 20]);

        Livewire::test(EditTrainingCourse::class, ['record' => $course->getRouteKey()])->assertOk()->assertSee('Quản lý đội nhóm');
        Livewire::test(ListTrainingCourses::class)->assertSee('CN, 15/11/2026 · Cafe A')->assertSee('và 1 ngày khác');

        // Thêm ngày tương tự: chỉ chọn ngày, giữ giờ / địa điểm / số người
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('repeat')->table($first), ['date' => '2026-11-29'])
            ->assertHasNoActionErrors();
        $copy = $course->sessions()->get()->last();
        $this->assertSame('2026-11-29 18:00', $copy->starts_at->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'));
        $this->assertTrue($copy->location->is($this->cafeA));
        $this->assertSame(10, $copy->capacity);

        // Huỷ ngày
        Livewire::test(ManageTrainingSessions::class, ['record' => $course->getRouteKey()])
            ->callAction(TestAction::make('cancel')->table($copy));
        $this->assertTrue($copy->fresh()->isCancelled());

    }

    public function test_locations_saved_and_delete_guarded(): void
    {
        $this->actingAs($this->admin);
        $this->addSession($this->course(), $this->cafeA, '2026-11-15', 10);

        $this->get(ManageTrainingLocations::getUrl())->assertOk()->assertSee('Cafe A')->assertSee('Mở bản đồ');
        Livewire::test(ManageTrainingLocations::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->cafeA))
            ->callAction(TestAction::make('delete')->table($this->cafeB));
        $this->assertSoftDeleted($this->cafeB);

        Livewire::test(ManageTrainingLocations::class)
            ->callAction('create', ['label' => 'Cafe C', 'address' => '1 Lê Lợi', 'map_url' => 'không phải link'])
            ->assertHasActionErrors(['map_url']);
    }

    public function test_api_lists_published_courses_with_upcoming_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Ho_Chi_Minh'));
        $later = $this->course(['title' => 'Sau']);
        $this->addSession($later, $this->cafeA, '2026-11-25', 10);

        $soon = $this->course(['title' => 'Sớm', 'content' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Giới thiệu']],
            ['type' => 'promo_code', 'data' => ['promo_code_id' => 1]], // không dùng ở khoá huấn luyện → bỏ
        ]]);
        $this->addSession($soon, $this->cafeB, '2026-10-20', 10);                        // đã qua
        $s1 = $this->addSession($soon, $this->cafeA, '2026-11-15', 10);
        $this->addSession($soon, $this->cafeB, '2026-11-18', 15, ['cancelled_at' => now()]); // đã huỷ
        $s2 = $this->addSession($soon, $this->cafeB, '2026-11-20', 20);

        $this->addSession($this->course(['title' => 'Nháp', 'status' => TrainingCourseStatus::Draft]), $this->cafeA, '2026-11-10', 5);
        $this->addSession($this->course(['title' => 'Hết lịch']), $this->cafeA, '2026-10-01', 5);

        $this->getJson('/v1/trainings')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Sớm')
            ->assertJsonPath('data.0.price', 200000)
            ->assertJsonPath('data.0.banner.width', 1280)
            ->assertJsonPath('data.0.next_session.id', $s1->id)
            ->assertJsonPath('data.0.next_session.location.label', 'Cafe A')
            ->assertJsonPath('data.0.upcoming_sessions_count', 2)
            ->assertJsonPath('data.1.title', 'Sau');

        $this->getJson("/v1/trainings/{$soon->id}")->assertOk()
            ->assertJsonPath('data.blocks', [['type' => 'paragraph', 'text' => 'Giới thiệu']])
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.sessions.1.id', $s2->id)
            ->assertJsonPath('data.sessions.1.capacity', 20)
            ->assertJsonPath('data.sessions.1.starts_at', '2026-11-20T11:00:00+00:00')
            ->assertJsonPath('data.sessions.1.location.map_url', 'https://maps.google.com/?q=b');

        $draft = TrainingCourse::where('title', 'Nháp')->sole();
        $this->getJson("/v1/trainings/{$draft->id}")->assertNotFound();
    }

    /** Link ảnh theo domain + cổng đang gọi (không theo APP_URL) — tránh ô ảnh CMS kẹt "Loading" khi khác origin. */
    public function test_public_file_urls_follow_request_host(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->addSession($this->course(), $this->cafeA, now('Asia/Ho_Chi_Minh')->addDays(5)->toDateString(), 10);

        $this->getJson('http://192.168.1.20:8000/v1/trainings')
            ->assertOk()
            ->assertJsonPath('data.0.banner.url', 'http://192.168.1.20:8000/storage/trainings/banners/x.png');
    }

    public function test_instructor_sees_only_own_courses_read_only(): void
    {
        $mine = $this->course(['title' => 'Khoá của tôi', 'trainer_id' => $this->teacher->id]);
        $session = $this->addSession($mine, $this->cafeA, now('Asia/Ho_Chi_Minh')->addDays(3)->toDateString(), 10);
        $other = $this->course(['title' => 'Khoá người khác']);

        $this->actingAs($this->teacher);
        Livewire::test(ListTrainingCourses::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden('create');

        $this->get(EditTrainingCourse::getUrl(['record' => $mine]))->assertForbidden();
        $this->get(ManageTrainingSessions::getUrl(['record' => $other]))->assertNotFound(); // ngoài phạm vi của giáo viên
        Livewire::test(ManageTrainingSessions::class, ['record' => $mine->getRouteKey()])
            ->assertCanSeeTableRecords([$session])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($session))
            ->assertActionHidden(TestAction::make('repeat')->table($session))
            ->assertActionHidden(TestAction::make('cancel')->table($session))
            ->assertActionHidden(TestAction::make('delete')->table($session));

        $this->get(ManageTrainingLocations::getUrl())->assertForbidden();

        // API trả thông tin người hướng dẫn
        $this->getJson("/v1/trainings/{$mine->id}")->assertJsonPath('data.trainer', ['id' => $this->teacher->id, 'name' => 'Cô Lan']);
    }

    /** Bỏ / thay ảnh rồi lưu, hoặc xoá khoá → file trên storage bị xoá (trừ khi bản ghi khác còn dùng). */
    public function test_removed_images_are_deleted_from_storage(): void
    {
        $disk = Storage::disk('public');
        foreach (['trainings/banners/a.png', 'trainings/banners/b.png', 'trainings/content/c.png', 'trainings/content/d.png'] as $path) {
            $disk->put($path, 'x');
        }

        $course = $this->course([
            'banner_path' => 'trainings/banners/a.png',
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

        // Bản nhân bản dùng chung banner + ảnh → xoá bản gốc vẫn giữ file
        $copy = $course->replicate();
        $copy->save();
        $course->delete();
        $disk->assertExists('trainings/banners/b.png');
        $disk->assertExists('trainings/content/d.png');

        // Xoá nốt bản còn lại → xoá file
        $copy->delete();
        $disk->assertMissing('trainings/banners/b.png');
        $disk->assertMissing('trainings/content/d.png');
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
