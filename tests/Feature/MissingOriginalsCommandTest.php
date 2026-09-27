<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Support\FailingFilesystemAdapter;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\TestCase;

class MissingOriginalsCommandTest extends TestCase
{
    use CreatesMedia;

    private const array HEADERS = ['Disk', 'Inspected', 'Present', 'Missing', 'Errors', 'Skipped', 'Deletion'];

    #[Test]
    public function it_reports_missing_originals_without_deleting_them(): void
    {
        $this->addManyMedia(10);
        $missing = $this->removeOriginal($this->addMedia());

        $this->artisan('media-library:doctor:missing-originals')
            ->expectsTable(self::HEADERS, [['media', 11, 10, 1, 0, 0, 'allowed']])
            ->assertSuccessful();

        $this->assertModelExists($missing);
    }

    #[Test]
    public function it_lists_the_missing_media_in_verbose_mode(): void
    {
        $this->addManyMedia(10);
        $missing = $this->removeOriginal($this->addMedia());

        $this->artisan('media-library:doctor:missing-originals -v')
            ->expectsOutput("Media #{$missing->getKey()} on disk `media` has no original file.")
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_when_no_media_matches(): void
    {
        $this->artisan('media-library:doctor:missing-originals')
            ->expectsOutput('No matching media rows found.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_deletes_media_whose_original_is_missing(): void
    {
        $present = $this->addManyMedia(10);
        $missing = $this->removeOriginal($this->addMedia());

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsTable(['Status', 'Count'], [
                ['Deleted', 1],
                ['File reappeared', 0],
                ['Already removed', 0],
                ['Failed', 0],
            ])
            ->assertSuccessful();

        $this->assertModelMissing($missing);
        $this->assertSame(10, Media::query()->whereKey(array_map(fn (Media $media) => $media->getKey(), $present))->count());
    }

    #[Test]
    public function it_asks_for_confirmation_before_deleting(): void
    {
        $this->addManyMedia(10);
        $missing = $this->removeOriginal($this->addMedia());

        $this->artisan('media-library:doctor:missing-originals --delete')
            ->expectsConfirmation('Delete 1 media row(s) whose original file is missing?', 'no')
            ->expectsOutput('Nothing was deleted.')
            ->assertFailed();

        $this->assertModelExists($missing);
    }

    #[Test]
    public function it_refuses_to_delete_when_too_many_files_are_missing(): void
    {
        $this->addManyMedia(2);
        $missing = $this->removeOriginal($this->addMedia());

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsOutput('Not deleting on disk `media`: 33.33% of the files are missing, above --max-missing-percent=10.')
            ->assertFailed();

        $this->assertModelExists($missing);
    }

    #[Test]
    public function it_refuses_to_delete_when_no_file_exists_on_the_disk(): void
    {
        $missing = array_map($this->removeOriginal(...), $this->addManyMedia(3));

        $this->artisan('media-library:doctor:missing-originals --delete --force --max-missing-percent=100')
            ->expectsTable(self::HEADERS, [['media', 3, 0, 3, 0, 0, 'no file could be found on the disk']])
            ->assertFailed();

        foreach ($missing as $media) {
            $this->assertModelExists($media);
        }
    }

    #[Test]
    public function it_deletes_only_on_disks_that_pass_the_safety_checks(): void
    {
        $this->addManyMedia(10);
        $missingOnMedia = $this->removeOriginal($this->addMedia());
        $missingOnBackup = $this->removeOriginal($this->addMedia(disk: 'backup'));

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsOutput('Not deleting on disk `backup`: no file could be found on the disk.')
            ->assertFailed();

        $this->assertModelMissing($missingOnMedia);
        $this->assertModelExists($missingOnBackup);
    }

    #[Test]
    public function it_ignores_recently_created_media(): void
    {
        $this->addManyMedia(10);
        $recent = $this->removeOriginal($this->addMedia(ageInMinutes: 5));

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsTable(self::HEADERS, [['media', 10, 10, 0, 0, 0, '-']])
            ->assertSuccessful();

        $this->assertModelExists($recent);

        $this->artisan('media-library:doctor:missing-originals --min-age=0')
            ->expectsTable(self::HEADERS, [['media', 11, 10, 1, 0, 0, 'allowed']])
            ->assertSuccessful();
    }

    #[Test]
    public function it_skips_media_on_unconfigured_disks(): void
    {
        $media = $this->addMedia();
        $media->forceFill(['disk' => 'ghost'])->saveQuietly();

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsTable(self::HEADERS, [['ghost', 1, 0, 0, 0, 1, 'disk is not configured']])
            ->assertSuccessful();

        $this->assertModelExists($media);
    }

    #[Test]
    public function it_does_not_treat_failed_checks_as_missing_files(): void
    {
        $this->registerFailingDisk();
        $media = $this->addMedia();
        $media->forceFill(['disk' => 'failing'])->saveQuietly();

        $this->artisan('media-library:doctor:missing-originals --delete --force')
            ->expectsTable(self::HEADERS, [['failing', 1, 0, 0, 1, 0, 'some existence checks failed']])
            ->expectsOutputToContain("Media #{$media->getKey()} on disk `failing`: Unable to check existence for:")
            ->assertFailed();

        $this->assertModelExists($media);
    }

    #[Test]
    public function it_aborts_the_scan_after_too_many_failed_checks(): void
    {
        $this->registerFailingDisk();

        foreach ($this->addManyMedia(3) as $media) {
            $media->forceFill(['disk' => 'failing'])->saveQuietly();
        }

        $this->artisan('media-library:doctor:missing-originals --delete --force --max-errors=2')
            ->expectsTable(self::HEADERS, [['failing', 2, 0, 0, 2, 0, 'some existence checks failed']])
            ->expectsOutput('Scan aborted after 2 failed existence check(s); nothing was deleted.')
            ->assertFailed();

        $this->assertSame(3, Media::query()->count());
    }

    #[Test]
    public function it_limits_the_scan_with_filters(): void
    {
        $this->addManyMedia(10);
        $this->removeOriginal($this->addMedia('avatars'));
        $this->removeOriginal($this->addMedia(disk: 'backup'));

        $this->artisan('media-library:doctor:missing-originals --collection=default --disk=media')
            ->expectsTable(self::HEADERS, [['media', 10, 10, 0, 0, 0, '-']])
            ->assertSuccessful();
    }

    #[Test]
    public function it_rejects_invalid_options(): void
    {
        $this->artisan('media-library:doctor:missing-originals --max-missing-percent=150 --min-age=-1')
            ->expectsOutput('Invalid value for --min-age.')
            ->expectsOutput('Invalid value for --max-missing-percent.')
            ->assertFailed();
    }

    private function registerFailingDisk(): void
    {
        Storage::extend('failing', function ($app, array $config): FilesystemAdapter {
            $adapter = new FailingFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        config(['filesystems.disks.failing' => ['driver' => 'failing', 'root' => sys_get_temp_dir().'/media-doctor-failing']]);
    }
}
