<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\EnrollmentSource;
use App\Enums\UserRole;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Students\Pages\StudentProfile;
use App\Filament\Resources\Students\RelationManagers\AppSessionsRelationManager;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\RelationManagers\LogsRelationManager;
use App\Filament\Resources\Students\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Students\RelationManagers\PlacementAttemptsRelationManager;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningPath;
use App\Models\PlacementAttempt;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\LearningProgress;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tài khoản › Học viên + Học bạ. */
class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private LearningPath $pathB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'name' => 'Học viên An']);
        $this->course = Course::create(['title' => 'Web AI', 'instructor_id' => $this->admin->id, 'price' => 0, 'type' => CourseType::MultiPath, 'status' => CourseStatus::Published]);
        $this->pathB = LearningPath::create(['course_id' => $this->course->id, 'name' => 'Lộ trình B']);
        $this->actingAs($this->admin);
    }

    public function test_students_and_staff_are_listed_separately(): void
    {
        $teacher = User::factory()->create(['role' => UserRole::Instructor]);

        Livewire::test(ManageStudents::class)
            ->assertCanSeeTableRecords([$this->student])
            ->assertCanNotSeeTableRecords([$this->admin, $teacher])
            ->assertActionVisible('invite');

        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $teacher])
            ->assertCanNotSeeTableRecords([$this->student])
            ->assertActionHidden('invite');
    }

    public function test_profile_shows_overview_and_ability(): void
    {
        $test = PlacementTest::create(['title' => 'Đầu vào', 'is_active' => true]);
        PlacementAttempt::create([
            'user_id' => $this->student->id, 'placement_test_id' => $test->id, 'answers' => [], 'completed_at' => now(),
            'results' => [['dimension_id' => 1, 'name' => 'Khả năng tiếp thu', 'score' => 9, 'min' => 0, 'max' => 10, 'level_id' => 1, 'level' => 'Xuất sắc', 'level_description' => null, 'rank' => 1, 'level_count' => 3]],
        ]);
        app(LearningProgress::class)->enroll($this->student, $this->course, EnrollmentSource::Admin, $this->pathB, $this->admin);

        $this->get(StudentResource::getUrl('profile', ['record' => $this->student]))
            ->assertOk()
            ->assertSee('Học bạ · Học viên An')
            ->assertSee('Khả năng tiếp thu: <b>Xuất sắc</b>', false)
            ->assertSee('Mở khoá học');

        foreach ([PlacementAttemptsRelationManager::class, MembershipsRelationManager::class, AppSessionsRelationManager::class, LogsRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class])->assertOk();
        }
    }

    public function test_enroll_change_path_and_revoke(): void
    {
        $other = Course::create(['title' => 'Khoá chung', 'instructor_id' => $this->admin->id, 'price' => 0, 'status' => CourseStatus::Published]);

        $manager = Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class]);

        // Khoá đa lộ trình, để trống lộ trình → học viên chọn trên app
        $manager->callAction(TestAction::make('enroll')->table(), ['course_id' => $this->course->id, 'note' => 'Tặng'])
            ->assertHasNoFormErrors();
        // Khoá chung → tự gán lộ trình duy nhất
        $manager->callAction(TestAction::make('enroll')->table(), ['course_id' => $other->id])->assertHasNoFormErrors();

        $multi = CourseEnrollment::where('course_id', $this->course->id)->sole();
        $this->assertNull($multi->learning_path_id);
        $this->assertSame(EnrollmentSource::Admin, $multi->source);
        $this->assertTrue($multi->grantedBy->is($this->admin));
        $this->assertSame('Tặng', $multi->note);
        $this->assertNotNull(CourseEnrollment::where('course_id', $other->id)->sole()->learning_path_id);

        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class])
            ->assertCanSeeTableRecords([$multi])
            ->assertSee('Chờ chọn lộ trình')
            ->callAction(TestAction::make('changePath')->table($multi), ['learning_path_id' => $this->pathB->id])
            ->assertHasNoFormErrors()
            ->mountAction(TestAction::make('detail')->table($multi))
            ->assertMountedActionModalSee('Lộ trình: Lộ trình B');
        $this->assertSame($this->pathB->id, $multi->fresh()->learning_path_id);

        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class])
            ->callAction(TestAction::make('delete')->table($multi));
        $this->assertModelMissing($multi);
    }

    public function test_grant_membership_from_profile(): void
    {
        Livewire::test(MembershipsRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class])
            ->callAction(TestAction::make('grantMembership')->table(), ['price' => 0, 'note' => 'Tặng'])
            ->assertHasNoFormErrors();

        $this->assertTrue($this->student->fresh()->hasActiveMembership());
    }
}
