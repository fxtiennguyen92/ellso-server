<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Dữ liệu demo chỉ chạy trên production khi bật ALLOW_DEMO_SEED (tài khoản demo có mật khẩu "password"). */
class DemoSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $seeders = collect(File::files(database_path('seeders')))
            ->map(fn ($f) => $f->getFilenameWithoutExtension())
            ->filter(fn ($name) => str_starts_with($name, 'Demo'));

        $this->assertNotEmpty($seeders);

        foreach ($seeders as $name) {
            try {
                $this->app->make('Database\\Seeders\\' . $name)->setContainer($this->app)->run();
                $this->fail("{$name} chạy được trên production");
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('Không chạy dữ liệu demo trên production', $e->getMessage(), $name);
            }
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_seed_allowed_on_production_with_flag(): void
    {
        $this->app['env'] = 'production';
        config(['ellso.allow_demo_seed' => true]);

        $this->app->make(\Database\Seeders\DemoCourseSeeder::class)->setContainer($this->app)->run();

        $this->assertDatabaseHas('users', ['email' => 'giangvien@ellso.test']);
    }

    public function test_database_seeder_on_production_creates_only_admin(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->run();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('courses', 0);
    }
}
