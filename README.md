# weldist/spatie-medialibrary-doctor

[![Tests](https://github.com/weldist/spatie-medialibrary-doctor/actions/workflows/tests.yml/badge.svg)](https://github.com/weldist/spatie-medialibrary-doctor/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Finds [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) media rows whose original file no longer exists on their disk, and removes them without putting healthy rows at risk.

> A [weld.ist](https://weld.ist) project.
>
> Unofficial plugin. Not affiliated with Spatie.

## The Problem

A media row can outlive its file: a failed upload, a bucket cleaned by hand, a restored database pointing at an older disk. media-library's own `media-library:clean` works the other way round (it removes files and directories that have no row), so nothing reports or removes these rows.

Doing it by hand with a loop of `exists()` calls is dangerous, because a filesystem driver cannot always tell "the file is not there" from "I am looking in the wrong place":

| Driver | File really missing | Network or permission error | Wrong configuration |
|---|---|---|---|
| Local | `false` | `false` | `false` (wrong `root`, unmounted volume) |
| S3 | `false` | exception | `false` (wrong bucket or prefix) |

A misconfigured disk makes every file look missing, and a naive cleanup deletes every row.

## The Solution

```bash
php artisan media-library:doctor:missing-originals            # report only
php artisan media-library:doctor:missing-originals --delete   # delete after the safety checks
```

- Checks only the original file with `fileExists()` on the media's own disk and path generator. Conversions and responsive images are not inspected.
- Reports by default. Rows are deleted only with `--delete`, after the whole scan has finished and after confirmation.
- Deletes with `$media->delete()`, so model events, observers and media-library's file remover run as usual.
- Works with any filesystem driver; nothing depends on a specific disk or path generator.

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

### `media-library:doctor:missing-originals`

Scans the media rows, prints a summary per disk and, with `--delete`, removes the rows whose original file is missing.

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

### Safety checks

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
