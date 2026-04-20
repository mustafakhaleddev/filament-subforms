<?php

namespace Wezlo\FilamentSubForms\Filament\Fields\Concerns;

use Closure;
use Filament\Resources\Events\RecordCreated;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Wezlo\FilamentSubForms\Filament\Pages\Concerns\IsSubFormPage;

/**
 * Shared create-via-page logic used by SubForm (BelongsTo pre-create)
 * and SubFormRepeater (HasMany item create). See the public method
 * docblock for the three execution paths.
 */
trait CreatesRelatedRecordViaPage
{
    /**
     * Create the related record via the target Resource's `CreateRecord`
     * page so user-authored hooks and events run.
     *
     *  1. **Trait path** — target page uses {@see IsSubFormPage}: call
     *     `createAsSubform($data)` which drives the page's full
     *     `create()` with side-effects neutralised. All hooks + events.
     *  2. **Replay path** — target page doesn't use the trait: replay
     *     `mutateFormDataBeforeCreate` → `beforeCreate` hook →
     *     `handleRecordCreation` → `afterCreate` hook → `RecordCreated`
     *     / `RecordSaved` events inline via `Closure::bind`.
     *  3. **Eloquent fallback** — no `create` page registered: plain
     *     `new Model; fill; save()`.
     *
     * @param  array<string, mixed>  $data
     */
    protected function createRelatedRecord(array $data): Model
    {
        $pageClass = $this->resolveCreatePageClass();

        if ($pageClass === null) {
            $relatedModelClass = $this->resolveRelatedModelClass();
            $record = new $relatedModelClass;
            $record->fill($data)->save();

            return $record;
        }

        $page = new $pageClass;

        if (in_array(IsSubFormPage::class, class_uses_recursive($pageClass), true)) {
            /** @var IsSubFormPage $page */
            return $page->createAsSubform($data, $this->except);
        }

        $mutatedData = $this->invokeProtectedOnPage(
            $page,
            $pageClass,
            fn (array $data): array => $this->mutateFormDataBeforeCreate($data),
            [$data],
        );

        $this->invokeProtectedOnPage(
            $page,
            $pageClass,
            fn (string $hook): mixed => $this->callHook($hook),
            ['beforeCreate'],
        );

        $record = $this->invokeProtectedOnPage(
            $page,
            $pageClass,
            fn (array $data): Model => $this->handleRecordCreation($data),
            [$mutatedData],
        );

        $this->invokeProtectedOnPage(
            $page,
            $pageClass,
            fn (string $hook): mixed => $this->callHook($hook),
            ['afterCreate'],
        );

        Event::dispatch(RecordCreated::class, ['record' => $record, 'data' => $mutatedData, 'page' => $page]);
        Event::dispatch(RecordSaved::class, ['record' => $record, 'data' => $mutatedData, 'page' => $page]);

        return $record;
    }

    /**
     * Resolve the related model class for the Eloquent fallback path.
     * Prefers the relationship-derived model when this consumer is a
     * Filament field with an active `->relationship()` (so `SubForm` and
     * `SubFormRepeater` keep their existing behaviour); otherwise falls
     * back to the target Resource's own model.
     *
     * @return class-string<Model>
     */
    protected function resolveRelatedModelClass(): string
    {
        if (method_exists($this, 'getRelatedModel')) {
            $modelFromRelationship = $this->getRelatedModel();

            if (filled($modelFromRelationship)) {
                return $modelFromRelationship;
            }
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        $resource = $this->getResource();

        return $resource::getModel();
    }

    /**
     * @return class-string<CreateRecord>|null
     */
    protected function resolveCreatePageClass(): ?string
    {
        $resource = $this->getResource();

        if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
            return null;
        }

        /** @var class-string<resource> $resource */
        $pages = $resource::getPages();
        $registration = $pages['create'] ?? null;

        if (! $registration instanceof PageRegistration) {
            return null;
        }

        $pageClass = $registration->getPage();

        if (! is_subclass_of($pageClass, CreateRecord::class)) {
            return null;
        }

        /** @var class-string<CreateRecord> $pageClass */
        return $pageClass;
    }

    /**
     * Bind and invoke a closure in the scope of the target page class so
     * it can reach protected methods.
     *
     * @template TReturn
     *
     * @param  class-string<CreateRecord>  $pageClass
     * @param  Closure(mixed ...): TReturn  $closure
     * @param  array<int, mixed>  $args
     * @return TReturn
     */
    protected function invokeProtectedOnPage(CreateRecord $page, string $pageClass, Closure $closure, array $args): mixed
    {
        return Closure::bind($closure, $page, $pageClass)(...$args);
    }
}
