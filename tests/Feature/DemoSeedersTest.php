<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Dữ liệu demo không bao giờ được chạy trên production (tài khoản demo có mật khẩu "password"). */
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
                $this->assertSame('Không chạy dữ liệu demo trên production.', $e->getMessage(), $name);
            }
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_seeder_on_production_creates_only_admin(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->run();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('courses', 0);
    }
}
