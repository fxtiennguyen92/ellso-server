<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\DocumentType;
use App\Enums\EnrollmentSource;
use App\Enums\MembershipSource;
use App\Enums\UserRole;
use App\Enums\VideoStatus;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Students\Pages\StudentProfile;
use App\Filament\Resources\Students\RelationManagers\BookPurchasesRelationManager;
use App\Jobs\ProcessDocumentVideo;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentPurchase;
use App\Models\User;
use App\Services\MembershipService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Tài liệu: điều kiện xem (miễn phí / gói thành viên / khoá học / bài học / mua sách), API app, CMS. */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents_local', ['serve' => true]);
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(($user ?? $this->student)->createToken('app', ['app'])->plainTextToken);
    }

    private function pdf(array $attrs = []): Document
    {
        Storage::disk('documents_local')->put('files/pdf/a.pdf', '%PDF-1.4 test');

        return Document::create([
            'type' => DocumentType::Pdf, 'title' => 'Tài liệu PDF', 'status' => 'published',
            'file_path' => 'files/pdf/a.pdf', 'file_name' => 'tai-lieu.pdf', 'file_format' => 'pdf', 'file_size' => 13,
            ...$attrs,
        ]);
    }

    public function test_access_conditions_and_open(): void
    {
        $free = $this->pdf(['is_free' => true, 'title' => 'Miễn phí']);
        $member = $this->pdf(['requires_membership' => true, 'title' => 'Thành viên']);
        $course = Course::create(['title' => 'Web AI', 'instructor_id' => $this->admin->id, 'price' => 0, 'status' => CourseStatus::Published]);
        $byCourse = $this->pdf(['title' => 'Theo khoá']);
        DocumentLink::create(['document_id' => $byCourse->id, 'course_id' => $course->id]);
        $draft = $this->pdf(['is_free' => true, 'status' => 'draft', 'title' => 'Nháp']);
        $processing = Document::create(['type' => DocumentType::Video, 'title' => 'Đang xử lý', 'is_free' => true, 'status' => 'published', 'video_status' => VideoStatus::Processing]);

        $list = collect($this->api()->getJson('/v1/documents')->assertOk()->json('data'))->keyBy('title');
        $this->assertEqualsCanonicalizing(['Miễn phí', 'Thành viên', 'Theo khoá'], $list->keys()->all()); // nháp + video chưa xong bị ẩn
        $this->assertTrue($list['Miễn phí']['unlocked']);
        $this->assertFalse($list['Thành viên']['unlocked']);
        $this->assertSame([['kind' => 'plus']], $list['Thành viên']['access']);
        $this->assertSame('course', $list['Theo khoá']['access'][0]['kind']);

        $this->api()->postJson("/v1/documents/{$member->id}/open")->assertForbidden()->assertJsonPath('code', 'locked');
        $this->api()->postJson("/v1/documents/{$byCourse->id}/open")->assertForbidden();
        $this->api()->getJson("/v1/documents/{$draft->id}")->assertNotFound();

        $open = $this->api()->postJson("/v1/documents/{$free->id}/open")->assertOk()->json('data');
        $this->assertNotEmpty($open['url']);
        $this->assertNull($open['download_url']); // PDF không cho tải

        // Mở gói thành viên + vào khoá học → mở được
        app(MembershipService::class)->grant($this->student, MembershipSource::Admin, 0);
        CourseEnrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => EnrollmentSource::Admin, 'enrolled_at' => now()]);
        $this->api()->postJson("/v1/documents/{$member->id}/open")->assertOk();
        $this->api()->postJson("/v1/documents/{$byCourse->id}/open")->assertOk();
        $this->api()->postJson("/v1/documents/{$processing->id}/play")->assertNotFound();
    }

    public function test_book_purchase_download_and_saves(): void
    {
        Storage::disk('documents_local')->put('files/book/s.epub', 'epub');
        $book = Document::create([
            'type' => DocumentType::Book, 'title' => 'Sách hay', 'author' => 'A', 'status' => 'published', 'price' => 99000, 'allow_download' => true,
            'file_path' => 'files/book/s.epub', 'file_name' => 'sach-hay.epub', 'file_format' => 'epub',
        ]);

        $item = $this->api()->getJson("/v1/documents/{$book->id}")->assertOk()->json('data');
        $this->assertSame([['kind' => 'buy', 'price' => 99000]], $item['access']);
        $this->assertFalse($item['unlocked']);
        $this->assertTrue($item['file']['downloadable']);

        DocumentPurchase::create(['user_id' => $this->student->id, 'document_id' => $book->id, 'price' => 99000, 'source' => 'purchase', 'purchased_at' => now()]);
        $open = $this->api()->postJson("/v1/documents/{$book->id}/open")->assertOk()->json('data');
        $this->assertNotNull($open['download_url']);

        $this->api()->postJson("/v1/documents/{$book->id}/save")->assertOk();
        $mine = $this->api()->getJson('/v1/my/documents')->assertOk()->json('data');
        $this->assertSame([$book->id], array_column($mine['saved'], 'id'));
        $this->assertSame([$book->id], array_column($mine['bought'], 'id'));
        $this->assertTrue($mine['bought'][0]['bought']);

        $this->api()->deleteJson("/v1/documents/{$book->id}/save")->assertOk();
        $this->assertSame([], $this->api()->getJson('/v1/my/documents')->json('data.saved'));

        // PDF / video không bán riêng: giá bị bỏ
        $pdf = $this->pdf(['is_free' => true, 'price' => 50000, 'allow_download' => true]);
        $this->assertNull($pdf->price);
        $this->assertFalse($pdf->allow_download);
    }

    public function test_cms_create_video_and_pdf(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        Livewire::test(ListDocuments::class)->assertOk();

        // Không chọn điều kiện xem → lỗi
        Livewire::test(CreateDocument::class)
            ->fillForm(['type' => 'pdf', 'title' => 'Thiếu điều kiện', 'file_path' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->call('create')
            ->assertHasFormErrors(['is_free']);

        Livewire::test(CreateDocument::class)
            ->fillForm([
                'type' => 'video', 'title' => 'Video mới', 'requires_membership' => true, 'status' => 'published',
                'video_source_path' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $video = Document::where('title', 'Video mới')->sole();
        $this->assertSame(VideoStatus::Pending, $video->video_status);
        $this->assertSame($this->admin->id, $video->created_by_id);
        Queue::assertPushed(ProcessDocumentVideo::class, fn ($job) => $job->documentId === $video->id);

        // Sách bán riêng
        Livewire::test(CreateDocument::class)
            ->fillForm([
                'type' => 'book', 'title' => 'Sách bán', 'sell' => true, 'price' => 120000, 'allow_download' => true, 'status' => 'published',
                'file_path' => UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 sách'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $book = Document::where('title', 'Sách bán')->sole();
        $this->assertSame(120000, $book->price);
        $this->assertSame('pdf', $book->file_format);
        $this->assertGreaterThan(0, $book->file_size);

        // Tắt bán riêng, bật miễn phí → giá bỏ
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->fillForm(['sell' => false, 'is_free' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($book->fresh()->price);
        $this->assertTrue($book->fresh()->is_free);
    }

    public function test_grant_book_in_student_profile(): void
    {
        $book = Document::create(['type' => DocumentType::Book, 'title' => 'Sách', 'status' => 'published', 'price' => 80000, 'file_path' => 'files/book/x.pdf']);
        $this->actingAs($this->admin);

        Livewire::test(BookPurchasesRelationManager::class, ['ownerRecord' => $this->student, 'pageClass' => StudentProfile::class])
            ->callAction(TestAction::make('grantBook')->table(), ['document_id' => $book->id, 'price' => 0, 'note' => 'Tặng'])
            ->assertHasNoFormErrors();

        $purchase = DocumentPurchase::sole();
        $this->assertSame('admin', $purchase->source);
        $this->assertSame($this->admin->id, $purchase->granted_by_id);
        $this->assertTrue($this->api()->getJson("/v1/documents/{$book->id}")->json('data.unlocked'));
    }

    public function test_author_is_teacher_or_external(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);
        $teacher = User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true, 'name' => 'Cô Lan']);

        Livewire::test(CreateDocument::class)
            ->fillForm(['type' => 'pdf', 'title' => 'Của giảng viên', 'is_free' => true, 'status' => 'published', 'author_kind' => 'teacher', 'author_id' => $teacher->id,
                'file_path' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF')])
            ->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateDocument::class)
            ->fillForm(['type' => 'pdf', 'title' => 'Sách ngoài', 'is_free' => true, 'status' => 'published', 'author_kind' => 'external', 'author' => 'Tác giả X',
                'file_path' => UploadedFile::fake()->createWithContent('b.pdf', '%PDF')])
            ->call('create')->assertHasNoFormErrors();

        $own = Document::where('title', 'Của giảng viên')->sole();
        $this->assertSame($teacher->id, $own->author_id);
        $this->assertNull($own->author);
        $this->assertSame([$own->id], $teacher->authoredDocuments()->pluck('id')->all());

        // Đổi sang tác giả ngoài → bỏ liên kết giảng viên
        Livewire::test(EditDocument::class, ['record' => $own->getRouteKey()])
            ->assertSchemaStateSet(['author_kind' => 'teacher'])
            ->fillForm(['author_kind' => 'external', 'author' => 'Người khác'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($own->fresh()->author_id);
        $own->fresh()->update(['author_id' => $teacher->id]);

        $list = collect($this->api()->getJson('/v1/documents')->json('data'))->keyBy('title');
        $this->assertSame('Cô Lan', $list['Của giảng viên']['author']);
        $this->assertSame($teacher->id, $list['Của giảng viên']['teacher']['id']);
        $this->assertSame('Tác giả X', $list['Sách ngoài']['author']);
        $this->assertNull($list['Sách ngoài']['teacher']);

        $this->assertSame(['Của giảng viên'], array_column($this->api()->getJson("/v1/documents?teacher_id={$teacher->id}")->json('data'), 'title'));
        $this->assertSame(['Của giảng viên'], array_column($this->api()->getJson('/v1/documents?q=Lan')->json('data'), 'title'));
    }

    public function test_instructor_cannot_access_documents(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get('/admin/documents')->assertForbidden();
    }
}
