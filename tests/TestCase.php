<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Weldist\Spatie\MediaLibrary\Doctor\DoctorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Storage::fake('backup');
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection((string) env('DB_DRIVER', 'sqlite')));

        $app['config']->set('filesystems.disks.media', ['driver' => 'local', 'root' => sys_get_temp_dir().'/media-doctor-tests/media']);
        $app['config']->set('filesystems.disks.backup', ['driver' => 'local', 'root' => sys_get_temp_dir().'/media-doctor-tests/backup']);
        $app['config']->set('media-library.disk_name', 'media');
        $app['config']->set('media-library.queue_conversions_by_default', false);
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            DoctorServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConnection(string $driver): array
    {
        $server = ['database' => 'testing', 'password' => 'secret', 'prefix' => ''];

        return match ($driver) {
            'mysql' => [...$server, 'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'mariadb' => [...$server, 'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'username' => 'root', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'pgsql' => [...$server, 'driver' => 'pgsql', 'host' => 'postgres', 'port' => 5432, 'username' => 'postgres', 'charset' => 'utf8', 'schema' => 'public'],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        };
    }
}
