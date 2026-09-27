# weldist/spatie-medialibrary-doctor

[![Tests](https://github.com/weldist/spatie-medialibrary-doctor/actions/workflows/tests.yml/badge.svg)](https://github.com/weldist/spatie-medialibrary-doctor/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Keeps the [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) `media` table and its disks in sync, in both directions and without putting healthy media at risk:

- media rows whose original file no longer exists on their disk,
- directories on a media disk that belong to no media row.

> A [weld.ist](https://weld.ist) project.
>
> Unofficial plugin. Not affiliated with Spatie.

## The Problem

**Rows without a file.** A media row can outlive its file: a failed upload, a bucket cleaned by hand, a restored database pointing at an older disk. Nothing in media-library reports or removes these rows.

Doing it by hand with a loop of `exists()` calls is dangerous, because a filesystem driver cannot always tell "the file is not there" from "I am looking in the wrong place":

| Driver | File really missing | Network or permission error | Wrong configuration |
|---|---|---|---|
| Local | `false` | `false` | `false` (wrong `root`, unmounted volume) |
| S3 | `false` | exception | `false` (wrong bucket or prefix) |

A misconfigured disk makes every file look missing, and a naive cleanup deletes every row.

**Files without a row.** Files stay behind when a row is deleted outside Eloquent, a file removal fails or a database is restored to an older state. media-library's `media-library:clean` removes directories without a row, but only at the top level of the disk, which does not reach the media directories of nested path generators.

Cleaning up by hand is dangerous here too. media-library writes to the `public` disk with no prefix by default, the same disk and root Laravel applications store their other uploads on, and Livewire and Filament use the application's default disk. A media disk commonly holds files that media-library does not own, and an empty `media` table or a wrong database connection makes every media file look orphaned.

## The Solution

```bash
php artisan media-library:doctor                              # check the configuration, touches no disk

php artisan media-library:doctor:missing-originals            # report rows without a file
php artisan media-library:doctor:missing-originals --delete   # delete them after the safety checks

php artisan media-library:doctor:orphaned-files               # report directories without a row
php artisan media-library:doctor:orphaned-files --delete      # delete them after the safety checks
```

- Reports by default. Nothing is deleted without `--delete`, and only after the whole scan has finished and after confirmation.
- Uses each media's own disk and path generator, so it works with any filesystem driver and any path generator.
- Judges every disk on its own, with safety checks that stop the deletion on a disk that looks misconfigured.

## Requirements

- PHP ^8.4
- Laravel ^12.0 | ^13.0
- `spatie/laravel-medialibrary ^11.0 | ^12.0`

## Installation

```bash
composer require weldist/spatie-medialibrary-doctor
```

The service provider is auto-discovered. There is no configuration file.

## Commands

### `media-library:doctor`

Checks the configuration and the `media` table in a few seconds, without listing, reading or deleting any file. Run it before the scans: it shows why they would skip rows or refuse to delete.

```
+-------+----------------+-------+-------------+
| Disk  | Driver         | Media | Conversions |
+-------+----------------+-------+-------------+
| ghost | not configured | 3     | 3           |
| media | s3             | 12400 | 12400       |
+-------+----------------+-------+-------------+
```

| Check | Fails or warns when |
|---|---|
| Disks are configured | **Fails** when a disk used by media is not defined in `filesystems.disks`. The scans skip these rows. |
| Path generators are valid | **Fails** when `path_generator` or an entry of `custom_path_generators` is not a `PathGenerator`. |
| Media prefix is set | **Warns** when `media-library.prefix` is empty. `orphaned-files` then deletes only with `--path`. |
| Media rows have created_at | **Warns** about rows without `created_at`. `missing-originals` inspects them only with `--min-age=0`. |

The command exits with a failure code when a check fails; warnings do not change the exit code. Global scopes of the media model are ignored.

### `media-library:doctor:missing-originals`

Scans the media rows, prints a summary per disk and, with `--delete`, removes the rows whose original file is missing. Only the original file is checked with `fileExists()`; conversions and responsive images are not inspected. Rows are deleted with `$media->delete()`, so model events, observers and media-library's file remover run as usual.

```
+-------+-----------+---------+---------+--------+---------+----------+
| Disk  | Inspected | Present | Missing | Errors | Skipped | Deletion |
+-------+-----------+---------+---------+--------+---------+----------+
| media | 12400     | 12363   | 37      | 0      | 0       | allowed  |
+-------+-----------+---------+---------+--------+---------+----------+
```

| Option | Meaning |
|---|---|
| `--delete` | Delete the rows whose original file is missing. Without it the command only reports. |
| `--force` | Delete without asking for confirmation. |
| `--min-age=60` | Only inspect media created at least this many minutes ago. `0` inspects every row. |
| `--max-missing-percent=10` | Refuse to delete on a disk where more than this percentage of the checked files is missing. |
| `--max-errors=10` | Abort the scan once this many existence checks have failed. |
| `--disk=*` | Only include media on these disks. |
| `--collection=*` | Only include these collections. |
| `--model=*` | Only include media of these model types. |
| `--id-from=`, `--id-to=` | Limit by media id range. |
| `--chunk=100` | Rows per database chunk. |

Use `-v` to list every media row found without its original file.

#### Safety checks

| Check | Effect |
|---|---|
| A check throws an exception | The row is counted as an error, never as missing. Nothing is deleted on a disk with errors. |
| `--max-errors` reached | The scan stops and nothing is deleted. |
| Missing share above `--max-missing-percent` | Nothing is deleted on that disk; the disk may be misconfigured. |
| No file found at all on a disk | Nothing is deleted on that disk, whatever the percentage. |
| Media younger than `--min-age` | Not inspected, so a file that is still being written is not mistaken for a missing one. With a positive `--min-age`, rows without `created_at` are not inspected either. |
| Disk not in `filesystems.disks` | Rows are counted as skipped and never deleted. |
| Right before each deletion | The row is reloaded and its file checked again; it is kept if the file has appeared or the check fails. |

Each disk is judged on its own: a disk that fails a check keeps its rows while the other disks are cleaned. The command exits with a failure code whenever a disk was blocked, a check failed or a deletion failed.

### `media-library:doctor:orphaned-files`

Lists the files of every media disk, prints a summary per disk and, with `--delete`, removes the directories that belong to no media row.

```
+-------+---------+-------+-------+----------+-------------+--------+-------+----------+
| Disk  | Path    | Files | Owned | Orphaned | Directories | Recent | Stray | Deletion |
+-------+---------+-------+-------+----------+-------------+--------+-------+----------+
| media | library | 38212 | 38170 | 42       | 9           | 1      | 0     | allowed  |
+-------+---------+-------+-------+----------+-------------+--------+-------+----------+
```

A media row owns the directories its path generator returns for it: `getPath()` on its disk, `getPathForConversions()` and `getPathForResponsiveImages()` on its conversions disk. A file inside one of them is owned. Otherwise the topmost directory that neither is nor contains an owned directory is orphaned and deleted as a whole:

```
library/
├── 12/                    owned by media #12, never looked into
│   ├── cover.jpg
│   └── conversions/thumb.jpg
├── 57/                    no media row: orphaned, deleted with its contents
│   ├── photo.jpg
│   └── conversions/photo-thumb.jpg
└── .gitignore             directly under the scanned path: stray, never deleted
```

Files inside an owned directory are never deleted, even when no row references their name. With a nested path generator (e.g. `ab/cd/{uuid}/`), shard directories that contain owned directories are kept, and a file placed directly in one of them is counted as stray.

| Option | Meaning |
|---|---|
| `--delete` | Delete the orphaned directories. Without it the command only reports. |
| `--force` | Delete without asking for confirmation. |
| `--path=` | Directory to scan, relative to the disk root. Defaults to `media-library.prefix`; `/` scans the disk root. |
| `--disk=*` | Only scan these disks. Defaults to every disk and conversions disk used by a media row, plus the configured `disk_name` and `conversions_disk_name`. |
| `--min-age=60` | Only delete directories whose newest file was modified at least this many minutes ago. |
| `--max-orphaned-percent=10` | Refuse to delete on a disk where more than this percentage of the files is orphaned. |
| `--chunk=100` | Rows per database chunk. |

Use `-v` to list every orphaned directory with its number of files.

The owned directories of a disk are kept in memory as 64-bit hashes, one per media: about 45 MB per million media, whatever the path length. The disk listing is streamed and not kept. For several million media on one disk, raise `memory_limit`.

A hash collision can only make an orphaned directory look owned, so it is kept rather than deleted.

#### Safety checks

| Check | Effect |
|---|---|
| The disk root is scanned | With an empty `media-library.prefix` and no `--path`, the command reports but does not delete, because the root of a media disk usually holds files media-library does not own. |
| Listing the disk fails | Nothing is deleted on that disk. |
| No media row points to the disk | Nothing is deleted on that disk; the database connection or the `media` table may be wrong. |
| No file belongs to a media row | Nothing is deleted on that disk; the prefix or the path generator may be wrong. |
| Orphaned share above `--max-orphaned-percent` | Nothing is deleted on that disk. |
| Newest file younger than `--min-age` | The directory is kept and counted as recent, so files of a media that is still being written or removed are left alone. A file without a modification time counts as recent. |
| Files directly under the scanned path | Counted as stray and never deleted. |
| Global scopes on the media model | Ignored when collecting the owned directories, so the files of soft-deleted or otherwise scoped media are never orphaned. |
| Right before the deletion | The owned directories are collected again; a directory that belongs to a media row by then is kept. |
| Disk not in `filesystems.disks` | The disk is skipped. |

As with `missing-originals`, each disk is judged on its own and the command exits with a failure code whenever a disk was blocked, a listing failed or a deletion failed.

## Testing

```bash
docker compose --profile php84 up --build --abort-on-container-exit --exit-code-from php84
docker compose --profile php85 up --build --abort-on-container-exit --exit-code-from php85
```

Each profile starts MySQL, MariaDB and PostgreSQL and runs the suite once per database, SQLite included. To run it on a single database:

```bash
docker compose --profile php84 run --rm --entrypoint "sh -c 'DB_DRIVER=pgsql vendor/bin/phpunit'" php84
```

## License

MIT. See [LICENSE.md](LICENSE.md).
