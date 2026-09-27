<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Support\ScopedMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Support\ShardedPathGenerator;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\TestCase;

class OrphanedFilesCommandTest extends TestCase
{
    use CreatesMedia;

    private const array HEADERS = ['Disk', 'Path', 'Files', 'Owned', 'Orphaned', 'Directories', 'Recent', 'Stray', 'Deletion'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['media-library.prefix' => 'library']);
    }

    #[Test]
    public function it_reports_orphaned_directories_without_deleting_them(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files')
            ->expectsTable(self::HEADERS, [['media', 'library', 11, 10, 1, 1, 0, 0, 'allowed']])
            ->assertSuccessful();

        Storage::disk('media')->assertExists('library/999/orphan.jpg');
    }

    #[Test]
    public function it_lists_the_orphaned_directories_in_verbose_mode(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files -v')
            ->expectsOutput('Orphaned directory `library/999` on disk `media` (1 file(s)).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_deletes_orphaned_directories(): void
    {
        $media = $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsTable(['Status', 'Count'], [
                ['Deleted', 1],
                ['Claimed by a media row', 0],
                ['Failed', 0],
            ])
            ->assertSuccessful();

        Storage::disk('media')->assertMissing('library/999/orphan.jpg');

        foreach ($media as $item) {
            Storage::disk('media')->assertExists($item->getPathRelativeToRoot());
        }
    }

    #[Test]
    public function it_keeps_the_conversions_of_existing_media_and_deletes_whole_orphaned_trees(): void
    {
        $media = $this->addMedia();
        $this->putFile("library/{$media->getKey()}/conversions/thumb.jpg");
        $this->putFile('library/999/orphan.jpg');
        $this->putFile('library/999/conversions/orphan-thumb.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force --max-orphaned-percent=100')
            ->expectsTable(self::HEADERS, [['media', 'library', 4, 2, 2, 1, 0, 0, 'allowed']])
            ->assertSuccessful();

        Storage::disk('media')->assertExists("library/{$media->getKey()}/conversions/thumb.jpg");
        Storage::disk('media')->assertMissing('library/999');
    }

    #[Test]
    public function it_asks_for_confirmation_before_deleting(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete')
            ->expectsConfirmation('Delete 1 orphaned directory(ies)?', 'no')
            ->expectsOutput('Nothing was deleted.')
            ->assertFailed();

        Storage::disk('media')->assertExists('library/999/orphan.jpg');
    }

    #[Test]
    public function it_keeps_recently_modified_directories(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg', ageInMinutes: 5);

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsTable(self::HEADERS, [['media', 'library', 11, 10, 1, 1, 1, 0, '-']])
            ->assertSuccessful();

        Storage::disk('media')->assertExists('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --min-age=0')
            ->expectsTable(self::HEADERS, [['media', 'library', 11, 10, 1, 1, 0, 0, 'allowed']])
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_delete_on_the_disk_root_unless_the_path_is_given(): void
    {
        config(['media-library.prefix' => '']);
        $this->addManyMedia(10);
        $this->putFile('999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsOutput('Not deleting on disk `media`: the disk root is scanned; set media-library.prefix or pass --path.')
            ->assertFailed();

        Storage::disk('media')->assertExists('999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force --path=/')
            ->assertSuccessful();

        Storage::disk('media')->assertMissing('999/orphan.jpg');
    }

    #[Test]
    public function it_refuses_to_delete_when_too_many_files_are_orphaned(): void
    {
        $this->addManyMedia(2);
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsOutput('Not deleting on disk `media`: 33.33% of the files are orphaned, above --max-orphaned-percent=10.')
            ->assertFailed();

        Storage::disk('media')->assertExists('library/999/orphan.jpg');
    }

    #[Test]
    public function it_refuses_to_delete_when_no_media_row_points_to_the_disk(): void
    {
        $this->putFile('library/5/orphan.jpg', disk: 'backup');

        $this->artisan('media-library:doctor:orphaned-files --disk=backup --delete --force --max-orphaned-percent=100')
            ->expectsTable(self::HEADERS, [['backup', 'library', 1, 0, 1, 1, 0, 0, 'no media row points to the disk']])
            ->assertFailed();

        Storage::disk('backup')->assertExists('library/5/orphan.jpg');
    }

    #[Test]
    public function it_refuses_to_delete_when_no_file_belongs_to_a_media_row(): void
    {
        array_map($this->removeOriginal(...), $this->addManyMedia(3));
        $this->putFile('library/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force --max-orphaned-percent=100')
            ->expectsTable(self::HEADERS, [['media', 'library', 1, 0, 1, 1, 0, 0, 'no file belongs to a media row']])
            ->assertFailed();

        Storage::disk('media')->assertExists('library/999/orphan.jpg');
    }

    #[Test]
    public function it_never_deletes_files_directly_under_the_scanned_path(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/.gitignore');

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsTable(self::HEADERS, [['media', 'library', 11, 10, 0, 0, 0, 1, '-']])
            ->assertSuccessful();

        Storage::disk('media')->assertExists('library/.gitignore');
    }

    #[Test]
    public function it_keeps_files_placed_directly_in_a_shard_directory_of_a_nested_path_generator(): void
    {
        config(['media-library.path_generator' => ShardedPathGenerator::class]);
        $this->addManyMedia(10);
        $this->putFile('library/shard/stray.jpg');
        $this->putFile('library/shard/999/orphan.jpg');

        $this->artisan('media-library:doctor:orphaned-files --delete --force')
            ->expectsTable(self::HEADERS, [['media', 'library', 12, 10, 1, 1, 0, 1, 'allowed']])
            ->assertSuccessful();

        Storage::disk('media')->assertExists('library/shard/stray.jpg');
        Storage::disk('media')->assertMissing('library/shard/999');
    }

    #[Test]
    public function it_ignores_the_global_scopes_of_the_media_model(): void
    {
        $this->addManyMedia(10);
        $this->putFile('library/999/orphan.jpg');
        config(['media-library.media_model' => ScopedMedia::class]);

        $this->artisan('media-library:doctor:orphaned-files --disk=media')
            ->expectsTable(self::HEADERS, [['media', 'library', 11, 10, 1, 1, 0, 0, 'allowed']])
            ->assertSuccessful();
    }

    #[Test]
    public function it_does_not_delete_when_listing_the_disk_fails(): void
    {
        $this->registerFailingDisk();

        $this->artisan('media-library:doctor:orphaned-files --disk=failing --delete --force')
            ->expectsTable(self::HEADERS, [['failing', 'library', 0, 0, 0, 0, 0, 0, 'listing the disk failed']])
            ->expectsOutputToContain('Listing disk `failing` failed:')
            ->assertFailed();
    }

    #[Test]
    public function it_skips_unconfigured_disks(): void
    {
        $this->artisan('media-library:doctor:orphaned-files --disk=ghost')
            ->expectsOutput('Skipping disk `ghost`: disk is not configured.')
            ->expectsOutput('No disk to scan.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_rejects_invalid_options(): void
    {
        $this->artisan('media-library:doctor:orphaned-files --max-orphaned-percent=150 --min-age=-1')
            ->expectsOutput('Invalid value for --min-age.')
            ->expectsOutput('Invalid value for --max-orphaned-percent.')
            ->assertFailed();
    }
}
