<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\LegalPages\LegalPageResource;
use App\Filament\Resources\LegalPages\Pages\EditLegalPage;
use App\Models\LegalPage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LegalMarkdown;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Hệ thống › Điều khoản & bảo mật + API /v1/legal/{slug}. */
class LegalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_pages_exist_and_api_is_public_with_variables(): void
    {
        $this->assertSame(['privacy', 'terms'], LegalPage::orderBy('slug')->pluck('slug')->all());
        SystemSetting::updateOrCreate(['key' => SystemSetting::COMPANY_NAME], ['value' => 'Công ty TNHH Ellso', 'type' => 'string']);
        SystemSetting::updateOrCreate(['key' => SystemSetting::SUPPORT_EMAIL], ['value' => 'hotro@ellso.edu.vn', 'type' => 'string']);

        $json = $this->getJson('/v1/legal/terms')->assertOk()
            ->assertJsonPath('data.title', 'Điều khoản sử dụng')
            ->assertJsonPath('data.version', '1.0')
            ->json('data');
        $this->assertSame('paragraph', $json['blocks'][0]['type']);
        $text = json_encode($json['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('<b>Công ty TNHH Ellso</b>', $text);
        $this->assertStringContainsString('hotro@ellso.edu.vn', $text);
        $this->assertStringNotContainsString('{ten_cong_ty}', $text);
        $this->assertContains('header', array_column($json['blocks'], 'type'));
        $this->assertContains('list', array_column($json['blocks'], 'type'));

        $this->getJson('/v1/legal/privacy')->assertOk()->assertJsonPath('data.title', 'Chính sách bảo mật');
        $this->getJson('/v1/legal/khac')->assertNotFound();
    }

    public function test_markdown_to_blocks_escapes_html(): void
    {
        $blocks = LegalMarkdown::toBlocks("## Mục 1\nDòng một\ndòng hai\n\n- **Đậm** và *nghiêng*\n- [Ellso](https://ellso.edu.vn)\n1. Một\n2. Hai\n\n<script>x</script>");
        $this->assertSame(['type' => 'header', 'data' => ['text' => 'Mục 1', 'level' => 2]], $blocks[0]);
        $this->assertSame('Dòng một dòng hai', $blocks[1]['data']['text']);
        $this->assertSame(['unordered', ['<b>Đậm</b> và <i>nghiêng</i>', '<a href="https://ellso.edu.vn">Ellso</a>']], [$blocks[2]['data']['style'], $blocks[2]['data']['items']]);
        $this->assertSame(['ordered', ['Một', 'Hai']], [$blocks[3]['data']['style'], $blocks[3]['data']['items']]);
        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $blocks[4]['data']['text']);
    }

    public function test_only_admin_edits_and_unknown_variables_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $teacher = User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]);
        $terms = LegalPage::where('slug', LegalPage::TERMS)->sole();

        $this->actingAs($teacher)->get(LegalPageResource::getUrl('index'))->assertForbidden();

        $this->actingAs($admin)->get(LegalPageResource::getUrl('index'))->assertOk()->assertSee('Điều khoản sử dụng')->assertSee('Chính sách bảo mật');
        Livewire::test(EditLegalPage::class, ['record' => $terms->getRouteKey()])
            ->fillForm(['content' => "## Mục\nLiên hệ {email_ho_tro} hoặc {sai_bien}"])
            ->call('save')
            ->assertHasFormErrors(['content']);

        Livewire::test(EditLegalPage::class, ['record' => $terms->getRouteKey()])
            ->fillForm(['version' => '1.1', 'content' => "## Mục mới\nNội dung {ten_cong_ty}"])
            ->callAction(TestAction::make('preview'))
            ->call('save')
            ->assertHasNoFormErrors();
        $terms->refresh();
        $this->assertSame('1.1', $terms->version);
        $this->assertSame($admin->id, $terms->updated_by_id);
        $this->getJson('/v1/legal/terms')->assertJsonPath('data.blocks.0.data.text', 'Mục mới');
    }
}
