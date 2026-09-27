<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';

    public function style(): string
    {
        return match ($this) {
            self::Ok => 'info',
            self::Warning => 'comment',
            self::Failed => 'error',
        };
    }
}
