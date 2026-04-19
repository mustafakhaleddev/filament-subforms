<?php

namespace Wezlo\FilamentSubForms;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentSubFormsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-subforms';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile();
    }
}
