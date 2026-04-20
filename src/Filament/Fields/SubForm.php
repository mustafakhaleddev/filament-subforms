<?php

namespace Wezlo\FilamentSubForms\Filament\Fields;

use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Concerns\HasName;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class SubForm extends Group
{
    use Concerns\CreatesRelatedRecordViaPage;
    use Concerns\ResolvesResourceSchema;
    use HasName;

    protected string $subFormRelationship = '';

    public static function make(array|Closure|string $schema = []): static
    {
        if (! is_string($schema)) {
            return parent::make($schema);
        }

        /** @var static $static */
        $static = app(static::class, ['schema' => []]);
        $static->subFormRelationship = $schema;
        $static->configure();
        $static->name($schema);
        $static->visibleOn('create');

        return $static;
    }

    public function getSubFormRelationship(): string
    {
        return $this->subFormRelationship;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->subFormRelationship === '') {
            return;
        }

        $this->relationship($this->subFormRelationship);

        // `->relationship()` calls `$this->dehydrated(false)`, which would strip
        // this component's state out of the dehydrated form data. We need the
        // child data to flow into the top-level form state so that
        // `dehydrateState()` below can pre-create the related record and rewrite
        // state before `handleRecordCreation` runs.
        $this->dehydrated(true);

        // Intentionally a `static` closure with a typed `$component` parameter:
        // Filament's closure evaluator injects the component that's currently
        // resolving the schema, so we never touch a captured `$this` that
        // might have been detached during cloning (e.g. Repeater items).
        $this->schema(static function (SubForm $component): array {
            $resource = $component->getResource();

            if ($resource === null) {
                return [];
            }

            if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
                throw new InvalidArgumentException(
                    "SubForm::make('{$component->getSubFormRelationship()}')->resource(...) expects a Filament Resource class-string; got [".(is_string($resource) ? $resource : gettype($resource)).'].'
                );
            }

            // Cycle guard: if this component's target Resource model is
            // already in scope (the page itself or an outer sub-form),
            // render no children.
            if ($component->hasAncestorEmbeddingResource($resource)) {
                return [];
            }

            // Bind the target's model to the Schema handed to `form()`.
            // Without this, any `Repeater::make(...)->relationship(...)`
            // inside the target's form would walk up looking for a
            // model, find none, and throw
            // "Call to a member function hasAttribute() on null".
            /** @var class-string<resource> $resource */
            $schema = $resource::form(
                Schema::make($component->getLivewire())->model($resource::getModel())
            );

            // Strip nested sub-forms whose target model would reintroduce
            // a model already in scope. Done here (in the outer's closure)
            // rather than on the inner component — visibility callbacks on
            // the inner SubForm/SubFormRepeater aren't evaluated reliably
            // on every render path, so the cleanest fix is to not place
            // those components into the tree at all.
            $blockedModels = [
                ...$component->collectAncestorModels(),
                $resource::getModel(),
            ];

            $children = $component->stripCyclicSubForms(
                $schema->getComponents(withActions: true, withHidden: true),
                $blockedModels,
            );

            return $component->filterResourceComponents($children);
        });
    }

    /**
     * Intercept the parent form's state dehydration so a `BelongsTo` related
     * record can be created BEFORE the parent's initial INSERT. This is what
     * lets the parent's foreign key column stay `NOT NULL` — by the time
     * `handleRecordCreation` sees the state, `client_id` is already populated
     * with the freshly-created Client's PK, and the nested `client` array has
     * been dropped.
     *
     * For `HasOne` / `MorphOne`, the parent must be saved first, so this method
     * is a no-op and the standard `saveRelationshipsBeforeChildrenUsing` hook
     * (registered by the `EntanglesStateWithSingularRelationship` trait) runs
     * after parent creation.
     *
     * For updates (parent record already exists), the standard hook also takes
     * over, performing an UPDATE on the related record in-place.
     *
     * @param  array<string, mixed>  $state
     */
    public function dehydrateState(array &$state, bool $isDehydrated = true): void
    {
        parent::dehydrateState($state, $isDehydrated);

        if (! $isDehydrated || ! $this->isDehydrated()) {
            return;
        }

        if ($this->subFormRelationship === '') {
            return;
        }

        // Edit mode: parent already exists. Let the trait's post-save hook
        // handle the UPDATE of the related record.
        $parentRecord = $this->getRecord();
        if ($parentRecord instanceof Model && $parentRecord->exists) {
            return;
        }

        $relationship = $this->getRelationship();
        if (! ($relationship instanceof BelongsTo)) {
            return;
        }

        // Use absolute paths so we write into the form's state tree regardless
        // of where the SubForm lives (e.g. Livewire binds the form to `data`,
        // so the child's absolute path is `data.client` not `client`).
        $subFormStatePath = $this->getStatePath();
        $containerStatePath = $this->getContainer()->getStatePath();

        $relatedData = Arr::get($state, $subFormStatePath);

        if (! is_array($relatedData) || blank(array_filter($relatedData, fn ($value): bool => $value !== null && $value !== ''))) {
            return;
        }

        $relatedData = $this->mutateRelationshipDataBeforeCreate($relatedData);

        $related = $this->createRelatedRecord($relatedData);

        // Mark this as the cached record so the standard post-save hook sees
        // an existing record and falls into its UPDATE branch (a no-op save
        // with the same data) rather than attempting a second CREATE.
        $this->cachedExistingRecord($related);

        $foreignKeyPath = filled($containerStatePath)
            ? $containerStatePath.'.'.$relationship->getForeignKeyName()
            : $relationship->getForeignKeyName();

        Arr::forget($state, $subFormStatePath);
        Arr::set($state, $foreignKeyPath, $related->getKey());
    }
}
