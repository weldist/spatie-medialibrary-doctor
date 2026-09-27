<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Support;

/**
 * Stores paths as 64-bit hashes; a collision can only make an orphaned path look owned, never the reverse.
 */
final class PathSet
{
    /** @var array<int, true> */
    private array $keys = [];

    public function add(string $path): void
    {
        $this->keys[self::key($path)] = true;
    }

    public function has(string $path): bool
    {
        return isset($this->keys[self::key($path)]);
    }

    private static function key(string $path): int
    {
        return unpack('J', hash('xxh64', $path, true))[1];
    }
}
