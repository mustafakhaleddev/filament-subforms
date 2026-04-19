<?php

namespace Wezlo\FilamentSubForms\Filament\Fields;

use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use InvalidArgumentException;

class SubFormRepeater extends Repeater
{
    use Concerns\ResolvesResourceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relationship();

        // `static` closure with typed `$component` parameter — see SubForm for
        // the rationale. Avoids stale-`$this` crashes when the Repeater clones
        // its default schema for each item row.
        $this->schema(static function (SubFormRepeater $component): array {
            $resource = $component->getResource();

            if ($resource === null) {
                return [];
            }

            if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
                throw new InvalidArgumentException(
                    "SubFormRepeater::make('{$component->getName()}')->resource(...) expects a Filament Resource class-string; got [".(is_string($resource) ? $resource : gettype($resource)).'].'
                );
            }

            // Cycle guard — see SubForm for rationale.
            if ($component->hasAncestorEmbeddingResource($resource)) {
                return [];
            }

            /** @var class-string<resource> $resource */
            $schema = $resource::form(Schema::make($component->getLivewire()));

            $blockedModels = [
                ...$component->collectAncestorModels(),
                $resource::getModel(),
            ];

            $children = $component->stripCyclicSubForms(
                $schema->getComponents(withActions: false, withHidden: true),
                $blockedModels,
            );

            return $component->filterResourceComponents($children);
        });
    }
}
