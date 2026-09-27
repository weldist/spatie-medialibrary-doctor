<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor;

use Illuminate\Support\ServiceProvider;
use Weldist\Spatie\MediaLibrary\Doctor\Console\DoctorCommand;
use Weldist\Spatie\MediaLibrary\Doctor\Console\MissingOriginalsCommand;
use Weldist\Spatie\MediaLibrary\Doctor\Console\OrphanedFilesCommand;

class DoctorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                MissingOriginalsCommand::class,
                OrphanedFilesCommand::class,
            ]);
        }
    }
}
