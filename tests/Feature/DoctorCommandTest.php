<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use stdClass;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Support\TestModel;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use CreatesMedia;

    private const array DISK_HEADERS = ['Disk', 'Driver', 'Media', 'Conversions'];

    private const array CHECK_HEADERS = ['Check', 'Status', 'Message'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['media-library.prefix' => 'library']);
    }

    #[Test]
    public function it_reports_a_healthy_setup(): void
    {
        $this->addManyMedia(3);
        $this->addMedia(disk: 'backup');

        $this->artisan('media-library:doctor')
            ->expectsTable(self::DISK_HEADERS, [
                ['backup', 'local', 1, 1],
                ['media', 'local', 3, 3],
            ])
            ->expectsTable(self::CHECK_HEADERS, [
                ['Disks are configured', 'ok', 'Every disk used by media is defined in filesystems.disks.'],
                ['Path generators are valid', 'ok', DefaultPathGenerator::class.'.'],
                ['Media prefix is set', 'ok', 'Media is stored under `library`.'],
                ['Media rows have created_at', 'ok', 'Every media row has created_at.'],
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_media_uses_an_unconfigured_disk(): void
    {
        $this->addMedia();
        $this->addManyMedia(2)[0]->forceFill(['disk' => 'ghost', 'conversions_disk' => 'ghost'])->saveQuietly();

        $this->artisan('media-library:doctor')
            ->expectsTable(self::DISK_HEADERS, [
                ['ghost', 'not configured', 1, 1],
                ['media', 'local', 2, 2],
            ])
            ->expectsOutputToContain('Not defined in filesystems.disks: `ghost` (1 row(s)). The doctor commands skip these rows.')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_on_an_invalid_path_generator(): void
    {
        config(['media-library.custom_path_generators' => [TestModel::class => stdClass::class]]);

        $this->artisan('media-library:doctor')
            ->expectsOutputToContain('Not a PathGenerator: '.TestModel::class.' => stdClass.')
            ->assertFailed();
    }

    #[Test]
    public function it_warns_when_the_prefix_is_empty(): void
    {
        config(['media-library.prefix' => '']);

        $this->artisan('media-library:doctor')
            ->expectsOutputToContain('media-library.prefix is empty; media-library:doctor:orphaned-files only deletes with --path.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_about_rows_without_a_creation_time(): void
    {
        $media = $this->addManyMedia(2);
        Media::query()->whereKey($media[0]->getKey())->update(['created_at' => null]);

        $this->artisan('media-library:doctor')
            ->expectsOutputToContain('1 media row(s) without created_at; media-library:doctor:missing-originals only inspects them with --min-age=0.')
            ->assertSuccessful();
    }
}
