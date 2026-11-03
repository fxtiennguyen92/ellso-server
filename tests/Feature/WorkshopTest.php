<?php

namespace Tests\Feature;

use App\Enums\MembershipSource;
use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\Workshops\Pages\CreateWorkshop;
use App\Filament\Resources\Workshops\Pages\EditWorkshop;
use App\Filament\Resources\Workshops\Pages\ListWorkshops;
use App\Filament\Resources\Workshops\Pages\ManageWorkshopRegistrations;
use App\Filament\Resources\Workshops\Pages\ManageWorkshopSessions;
use App\Mail\WorkshopLinkMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Workshop online: một buổi lặp nhiều ngày, đăng ký từng buổi, số tối đa tuỳ chọn, link họp, email gửi link. */
class WorkshopTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true, 'name' => 'Thầy Minh']);
    }

    private function workshop(array $attributes = []): Workshop
    {
        return Workshop::create([
            'title' => 'Thuyết trình',
            'banner_path' => 'workshops/banners/x.png',
            'price' => 0,
            'capacity' => null,
            'trainer_id' => $this->teacher->id,
            'status' => TrainingCourseStatus::Published,
            ...$attributes,
        ]);
    }

    private function vn(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh')->utc();
    }

    private function addSession(Workshop $workshop, string $date, string $from = '19:00', string $to = '21:00', array $extra = []): WorkshopSession
    {
        return WorkshopSession::create([
            'workshop_id' => $workshop->id,
            'starts_at' => $this->vn("{$date} {$from}"),
            'ends_at' => $this->vn("{$date} {$to}"),
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

    /** Gọi API bằng token của $user (quên user đã đăng nhập ở request trước / phiên CMS). */
    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('app')->plainTextToken);
    }

    public function test_cms_create_workshop_and_schedule_sessions(): void
    {
        $this->actingAs($this->admin);
        $this->get(ListWorkshops::getUrl())->assertOk()->assertSee('Workshop');

        $undo = Builder::fake();
        Livewire::test(CreateWorkshop::class)
            ->fillForm([
                'title' => 'Giao tiếp tự tin',
                'price' => 0,
                'trainer_id' => $this->teacher->id,
                'limit_capacity' => true,
                'capacity' => 30,
                'status' => TrainingCourseStatus::Published->value,
                'banner_path' => UploadedFile::fake()->image('banner.png', 1280, 720),
                'content' => [['type' => 'paragraph', 'data' => ['text' => 'Online qua Zoom']]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $workshop = Workshop::sole();
        $this->assertSame(30, $workshop->capacity);
        $this->assertTrue($workshop->createdBy->is($this->admin));

        // Tắt giới hạn → không giới hạn
        $undo = Builder::fake();
        Livewire::test(EditWorkshop::class, ['record' => $workshop->getRouteKey()])
            ->assertSchemaStateSet(['limit_capacity' => true])
            ->fillForm(['limit_capacity' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $undo();
        $this->assertNull($workshop->fresh()->capacity);

        // Lịch: nhiều ngày, khung giờ khác nhau, link họp
        $page = Livewire::test(ManageWorkshopSessions::class, ['record' => $workshop->getRouteKey()]);
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-20', 'start_time' => '19:30', 'end_time' => '21:30', 'meeting_url' => 'https://zoom.us/j/111',
        ])->assertHasNoFormErrors();
        $page->callAction(TestAction::make('create')->table(), [
            'date' => '2026-11-22', 'start_time' => '09:00', 'end_time' => '11:00', 'meeting_url' => 'không phải link',
        ])->assertHasFormErrors(['meeting_url']);

        $first = $workshop->sessions()->sole();
        $this->assertSame('2026-11-20 19:30', $first->starts_at->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'));
        $this->assertSame('https://zoom.us/j/111', $first->meeting_url);

        // Thêm buổi tương tự: cùng giờ + link, chỉ chọn ngày
        Livewire::test(ManageWorkshopSessions::class, ['record' => $workshop->getRouteKey()])
            ->callAction(TestAction::make('repeat')->table($first), ['date' => '2026-11-27'])
            ->assertHasNoActionErrors();
        $copy = $workshop->sessions()->get()->last();
        $this->assertSame('2026-11-27 19:30', $copy->starts_at->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'));
        $this->assertSame('https://zoom.us/j/111', $copy->meeting_url);

        // Huỷ buổi
        Livewire::test(ManageWorkshopSessions::class, ['record' => $workshop->getRouteKey()])
            ->callAction(TestAction::make('cancel')->table($copy));
        $this->assertTrue($copy->fresh()->isCancelled());

        // Nhân bản: không có lịch
        Livewire::test(ListWorkshops::class)->callAction(TestAction::make('replicate')->table($workshop));
        $this->assertSame(0, Workshop::where('title', 'Giao tiếp tự tin (bản sao)')->sole()->sessions()->count());
    }

    public function test_registration_per_session_capacity_membership_payment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Ho_Chi_Minh'));
        $free = $this->workshop(['capacity' => 1]);
        $s1 = $this->addSession($free, '2026-11-20');
        $s2 = $this->addSession($free, '2026-11-21', '09:00', '11:00');
        $paid = $this->workshop(['title' => 'Có phí', 'price' => 150_000]);
        $p1 = $this->addSession($paid, '2026-11-22');

        $this->postJson("/v1/workshop-sessions/{$s1->id}/register")->assertUnauthorized();
        $this->api($this->student(false))->postJson("/v1/workshop-sessions/{$s1->id}/register")
            ->assertForbidden()->assertJsonPath('code', 'membership_required');

        $a = $this->student(true, 'An');
        $this->api($a)->postJson("/v1/workshop-sessions/{$p1->id}/register")
            ->assertStatus(402)->assertJsonPath('code', 'payment_required');

        $this->api($a)->postJson("/v1/workshop-sessions/{$s1->id}/register")->assertCreated()
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.seats_left', 0)
            ->assertJsonPath('data.full', true)
            ->assertJsonPath('data.meeting_url', null);

        // Tối đa 1 người mỗi buổi: buổi 1 đã đủ, buổi 2 còn chỗ
        $b = $this->student(true, 'Bình');
        $this->api($b)->postJson("/v1/workshop-sessions/{$s1->id}/register")->assertStatus(409)->assertJsonPath('code', 'full');
        $this->api($b)->postJson("/v1/workshop-sessions/{$s2->id}/register")->assertCreated();

        // Huỷ trước giờ bắt đầu
        $this->api($b)->deleteJson("/v1/workshop-sessions/{$s2->id}/register")->assertOk()->assertJsonPath('data.registered', false);

        // Buổi đã huỷ / đã bắt đầu → không đăng ký được
        $s2->update(['cancelled_at' => now()]);
        $this->api($b)->postJson("/v1/workshop-sessions/{$s2->id}/register")->assertStatus(409)->assertJsonPath('code', 'registration_closed');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 19:10', 'Asia/Ho_Chi_Minh'));
        $this->api($a)->deleteJson("/v1/workshop-sessions/{$s1->id}/register")->assertStatus(409)->assertJsonPath('code', 'cancel_closed');
    }

    public function test_meeting_link_visible_only_to_registered_from_30_minutes_before(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 18:00', 'Asia/Ho_Chi_Minh'));
        $workshop = $this->workshop();
        $session = $this->addSession($workshop, '2026-11-20', '19:00', '21:00', ['meeting_url' => 'https://meet.google.com/abc-defg-hij', 'note' => 'Bật camera']);
        $this->addSession($workshop, '2026-11-27');
        $a = $this->student(true, 'An');
        WorkshopRegistration::create(['workshop_session_id' => $session->id, 'user_id' => $a->id, 'source' => RegistrationSource::App]);
        $other = $this->student(true, 'Bình');

        // 1 giờ trước: chưa có link, kèm thời điểm mở link
        $this->api($a)->getJson("/v1/workshops/{$workshop->id}")->assertOk()
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.capacity', null)
            ->assertJsonPath('data.sessions.0.registered', true)
            ->assertJsonPath('data.sessions.0.seats_left', null)
            ->assertJsonPath('data.sessions.0.meeting_url', null)
            ->assertJsonPath('data.sessions.0.link_available_at', '2026-11-20T11:30:00+00:00')
            ->assertJsonPath('data.sessions.1.registered', false);

        // 30 phút trước: người đã đăng ký thấy link; người khác / khách không thấy
        $this->travelTo(CarbonImmutable::parse('2026-11-20 18:30', 'Asia/Ho_Chi_Minh'));
        $this->api($a)->getJson("/v1/workshops/{$workshop->id}")
            ->assertJsonPath('data.sessions.0.meeting_url', 'https://meet.google.com/abc-defg-hij');
        $this->api($other)->getJson("/v1/workshops/{$workshop->id}")
            ->assertJsonPath('data.sessions.0.registered', false)
            ->assertJsonPath('data.sessions.0.meeting_url', null)
            ->assertJsonPath('data.sessions.0.link_available_at', null);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson("/v1/workshops/{$workshop->id}")->assertJsonPath('data.sessions.0.registered', null)->assertJsonPath('data.sessions.0.meeting_url', null);

        // Đang diễn ra: vẫn còn trong chi tiết + "của tôi", vẫn thấy link
        $this->travelTo(CarbonImmutable::parse('2026-11-20 20:00', 'Asia/Ho_Chi_Minh'));
        $this->api($a)->getJson('/v1/my/workshops')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.state', 'ongoing')
            ->assertJsonPath('data.0.meeting_url', 'https://meet.google.com/abc-defg-hij')
            ->assertJsonPath('data.0.workshop.title', 'Thuyết trình');

        // Kết thúc: hết link; buổi không còn trong chi tiết
        $this->travelTo(CarbonImmutable::parse('2026-11-20 21:01', 'Asia/Ho_Chi_Minh'));
        $this->api($a)->getJson('/v1/my/workshops')->assertJsonPath('data.0.meeting_url', null)->assertJsonPath('data.0.state', 'past');
        $this->flushHeaders()->getJson("/v1/workshops/{$workshop->id}")->assertJsonCount(1, 'data.sessions');
    }

    public function test_api_list_only_open_workshops_with_upcoming_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Ho_Chi_Minh'));
        $later = $this->workshop(['title' => 'Sau', 'capacity' => 20, 'price' => 150_000]);
        $this->addSession($later, '2026-11-25');
        $soon = $this->workshop(['title' => 'Sớm']);
        $this->addSession($soon, '2026-10-20');                                       // đã qua
        $next = $this->addSession($soon, '2026-11-10');
        $this->addSession($soon, '2026-11-08', '19:00', '21:00', ['cancelled_at' => now()]); // đã huỷ
        $this->addSession($this->workshop(['title' => 'Nháp', 'status' => TrainingCourseStatus::Draft]), '2026-11-05');

        $this->getJson('/v1/workshops')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Sớm')
            ->assertJsonPath('data.0.is_free', true)
            ->assertJsonPath('data.0.next_session.id', $next->id)
            ->assertJsonPath('data.0.upcoming_sessions_count', 1)
            ->assertJsonPath('data.0.trainer.name', 'Thầy Minh')
            ->assertJsonPath('data.1.title', 'Sau')
            ->assertJsonPath('data.1.price', 150000)
            ->assertJsonPath('data.1.capacity', 20);

        $this->getJson("/v1/workshops/{$later->id}")->assertJsonPath('data.sessions.0.seats_left', 20);
        $draft = Workshop::where('title', 'Nháp')->sole();
        $this->getJson("/v1/workshops/{$draft->id}")->assertNotFound();
    }

    public function test_send_link_emails_every_registrant(): void
    {
        Mail::fake();
        $this->actingAs($this->admin);
        $workshop = $this->workshop(['title' => 'Thuyết trình', 'capacity' => 10]);
        $session = $this->addSession($workshop, now('Asia/Ho_Chi_Minh')->addDays(3)->toDateString(), '19:00', '21:00', ['meeting_url' => 'https://zoom.us/j/999']);
        $noLink = $this->addSession($workshop, now('Asia/Ho_Chi_Minh')->addDays(4)->toDateString());
        [$a, $b] = [$this->student(true, 'An'), $this->student(true, 'Bình')];

        // Admin thêm người đăng ký (chọn buổi + học viên); chưa có gói → không thêm được
        Livewire::test(ManageWorkshopRegistrations::class, ['record' => $workshop->getRouteKey()])
            ->callAction(TestAction::make('addRegistrant')->table(), ['workshop_session_id' => $session->id, 'user_id' => $a->id])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('addRegistrant')->table(), ['workshop_session_id' => $session->id, 'user_id' => $b->id])
            ->callAction(TestAction::make('addRegistrant')->table(), ['workshop_session_id' => $session->id, 'user_id' => $this->student(false, 'Chi')->id]);
        $this->assertSame(2, $session->registrations()->count());
        $this->assertSame(RegistrationSource::Admin, $session->registrations()->first()->source);

        // Lọc theo buổi (mở từ tab Lịch: ?session=)
        Livewire::withQueryParams(['session' => $noLink->id])
            ->test(ManageWorkshopRegistrations::class, ['record' => $workshop->getRouteKey()])
            ->assertCanNotSeeTableRecords($session->registrations()->get());

        // Không có link / không có người đăng ký → nút gửi bị khoá
        Livewire::test(ManageWorkshopSessions::class, ['record' => $workshop->getRouteKey()])
            ->assertActionDisabled(TestAction::make('sendLink')->table($noLink))
            ->assertActionEnabled(TestAction::make('sendLink')->table($session))
            ->callAction(TestAction::make('sendLink')->table($session));

        Mail::assertQueued(WorkshopLinkMail::class, 2);
        Mail::assertQueued(WorkshopLinkMail::class, fn (WorkshopLinkMail $m) => $m->hasTo($a->email)
            && $m->meetingUrl === 'https://zoom.us/j/999'
            && $m->workshopTitle === 'Thuyết trình'
            && str_contains($m->when, '19:00 – 21:00'));
        $session->refresh();
        $this->assertNotNull($session->link_sent_at);
        $this->assertSame(2, $session->link_sent_count);

        // Nội dung email theo mẫu "Link tham gia workshop" (sửa được trên CMS)
        $mail = WorkshopLinkMail::forRecipient($a, 'Thuyết trình', '19:00 – 21:00, T5 20/11/2026', 'https://zoom.us/j/999');
        $this->assertSame('Link tham gia "Thuyết trình" — 19:00 – 21:00, T5 20/11/2026', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Vào phòng workshop', $html);
        $this->assertStringContainsString('https://zoom.us/j/999', $html);
        $this->assertStringContainsString('Xin chào An', $html);
    }

    public function test_workshop_email_template_editable_with_preview(): void
    {
        Mail::fake();
        $this->actingAs($this->admin);
        $template = EmailTemplate::forKey(EmailTemplate::WORKSHOP_LINK);

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->assertSee('{link}')
            ->fillForm(['intro' => 'Chào {ten}, link: {link}'])
            ->mountAction('preview')
            ->assertMountedActionModalSee(['Chào Nguyễn Văn An, link: https://zoom.us/j/1234567890', 'Vào phòng workshop']);

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['intro' => 'Hết hạn {han_dung}'])
            ->call('save')
            ->assertHasFormErrors(['intro']); // biến của mẫu mật khẩu tạm, không dùng ở đây

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->callAction('sendTest');
        Mail::assertSent(WorkshopLinkMail::class, fn ($m) => $m->hasTo($this->admin->email));
    }

    public function test_instructor_sees_own_workshops_read_only(): void
    {
        $mine = $this->workshop(['title' => 'Của tôi']);
        $session = $this->addSession($mine, now('Asia/Ho_Chi_Minh')->addDays(3)->toDateString(), '19:00', '21:00', ['meeting_url' => 'https://zoom.us/j/1']);
        $registration = WorkshopRegistration::create(['workshop_session_id' => $session->id, 'user_id' => $this->student()->id, 'source' => RegistrationSource::App]);
        $other = $this->workshop(['title' => 'Người khác', 'trainer_id' => null]);

        $this->actingAs($this->teacher);
        Livewire::test(ListWorkshops::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden('create');
        $this->get(EditWorkshop::getUrl(['record' => $mine]))->assertForbidden();
        $this->get(ManageWorkshopSessions::getUrl(['record' => $other]))->assertNotFound();

        Livewire::test(ManageWorkshopSessions::class, ['record' => $mine->getRouteKey()])
            ->assertCanSeeTableRecords([$session])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('sendLink')->table($session))
            ->assertActionHidden(TestAction::make('edit')->table($session));
        Livewire::test(ManageWorkshopRegistrations::class, ['record' => $mine->getRouteKey()])
            ->assertCanSeeTableRecords([$registration])
            ->assertActionHidden(TestAction::make('addRegistrant')->table())
            ->assertActionHidden(TestAction::make('delete')->table($registration));
    }
}
