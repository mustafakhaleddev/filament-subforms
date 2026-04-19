<?php

namespace Wezlo\FilamentSubForms;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentSubFormsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-subforms';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public static function current(): ?static
    {
        try {
            return filament()->getCurrentOrDefaultPanel()->getPlugin('filament-subforms');
        } catch (\Throwable) {
            return null;
        }
    }
}
