<?php

namespace Tests\Feature\Api;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_tagline_default_and_editable(): void
    {
        $this->getJson('/v1/app-config')->assertOk()->assertJsonPath('data.home_tagline', 'Hôm nay học gì cùng Ellso?');

        SystemSetting::query()->where('key', 'home_tagline')->first()->update(['value' => 'Học 15 phút mỗi ngày nhé!']);
        $this->getJson('/v1/app-config')->assertJsonPath('data.home_tagline', 'Học 15 phút mỗi ngày nhé!');

        SystemSetting::query()->where('key', 'home_tagline')->first()->update(['value' => '  ']);
        $this->getJson('/v1/app-config')->assertJsonPath('data.home_tagline', 'Hôm nay học gì cùng Ellso?');
    }
}
