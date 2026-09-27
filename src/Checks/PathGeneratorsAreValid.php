<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

final class PathGeneratorsAreValid implements Check
{
    public function name(): string
    {
        return 'Path generators are valid';
    }

    public function run(): CheckResult
    {
        $generators = [
            'default' => config('media-library.path_generator'),
            ...config('media-library.custom_path_generators', []),
        ];

        $invalid = array_filter($generators, fn (mixed $class) => ! is_string($class) || ! is_subclass_of($class, PathGenerator::class));

        if ($invalid !== []) {
            $list = implode(', ', array_map(fn (string $model, mixed $class) => "{$model} => ".(is_string($class) ? $class : get_debug_type($class)), array_keys($invalid), $invalid));

            return CheckResult::failed("Not a PathGenerator: {$list}.");
        }

        $custom = count($generators) - 1;

        return CheckResult::ok($generators['default'].($custom > 0 ? " and {$custom} custom generator(s)." : '.'));
    }
}
