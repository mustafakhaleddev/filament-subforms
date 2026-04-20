<?php

namespace Wezlo\FilamentSubForms\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Wezlo\FilamentSubForms\Filament\Fields\Concerns\CreatesRelatedRecordViaPage;

/**
 * Header / row / page action that embeds another Filament Resource's
 * form in its modal. On submit, the record is created through the
 * target Resource's `CreateRecord` page via the same pipeline as
 * `SubForm` / `SubFormRepeater` — trait path (`IsSubFormPage`) →
 * replay path → Eloquent fallback.
 *
 * ```php
 * use Wezlo\FilamentSubForms\Filament\Actions\FormAction;
 *
 * FormAction::make('create_client')
 *     ->resource(\App\Filament\Resources\ClientResource::class)
 *     ->beforeSave(function (array $data): void { ... })
 *     ->afterSave(function (Model $record): void { ... });
 * ```
 */
class FormAction extends Action
{
    use CreatesRelatedRecordViaPage;

    protected string|Closure|null $resource = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $only = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $except = null;

    protected ?Closure $beforeSaveCallback = null;

    protected ?Closure $afterSaveCallback = null;

    protected string|Closure|null $operation = null;

    public function resource(string|Closure $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getResource(): ?string
    {
        return $this->evaluate($this->resource);
    }

    /**
     * @param  array<int, string>|Closure  $fields
     */
    public function only(array|Closure $fields): static
    {
        $this->only = $fields;

        return $this;
    }

    /**
     * @param  array<int, string>|Closure  $fields
     */
    public function except(array|Closure $fields): static
    {
        $this->except = $fields;

        return $this;
    }

    public function beforeSave(Closure $callback): static
    {
        $this->beforeSaveCallback = $callback;

        return $this;
    }

    public function afterSave(Closure $callback): static
    {
        $this->afterSaveCallback = $callback;

        return $this;
    }

    /**
     * Set the Schema operation (`'create'`, `'edit'`, `'view'`, …) so
     * components inside the action's modal can rely on `->visibleOn()`,
     * `->hiddenOn()`, and similar operation-aware helpers. Defaults to
     * `'create'` when unset, matching the typical "create record via
     * action" use case.
     */
    public function operation(string|Closure|null $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getOperation(): ?string
    {
        return $this->evaluate($this->operation) ?? 'create';
    }

    /**
     * Apply the action's configured `operation()` to the modal's
     * Schema so components inside it can use `->visibleOn('create')` /
     * `->hiddenOn('edit')` / etc. against the expected context.
     * Defaults to `'create'`.
     */
    public function getSchema(Schema $schema): ?Schema
    {
        $configured = parent::getSchema($schema);

        return $configured?->operation($this->getOperation());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(static function (FormAction $action): array {
            $resource = $action->getResource();

            if ($resource === null) {
                return [];
            }

            if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
                throw new InvalidArgumentException(
                    "FormAction::make('{$action->getName()}')->resource(...) expects a Filament Resource class-string; got [".(is_string($resource) ? $resource : gettype($resource)).'].'
                );
            }

            /** @var class-string<resource> $resource */
            $schema = $resource::form(
                Schema::make($action->getLivewire())->model($resource::getModel())
            );

            return $action->filterActionComponents(
                $schema->getComponents(withActions: true, withHidden: true),
            );
        });

        $this->action(function (array $data, Schema $schema, FormAction $action): void {
            $data = $action->runBeforeSave($data);

            $record = $action->createRelatedRecord($data);

            // Fire saveRelationships() on the Action's own schema —
            // which holds the user's actual input — so nested
            // `SubFormRepeater` / `SubForm` fields inside the target
            // Resource's form persist against the just-created record.
            // Calling it on the target page's form doesn't work: that
            // form is a separate schema built from scratch, with no
            // state of its own.
            $schema->model($record)->saveRelationships();

            $action->runAfterSave($data, $record);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function runBeforeSave(array $data): array
    {
        if (! $this->beforeSaveCallback instanceof Closure) {
            return $data;
        }

        $returned = $this->evaluate($this->beforeSaveCallback, [
            'data' => $data,
            'action' => $this,
        ]);

        return is_array($returned) ? $returned : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function runAfterSave(array $data, Model $record): void
    {
        if (! $this->afterSaveCallback instanceof Closure) {
            return;
        }

        $this->evaluate($this->afterSaveCallback, [
            'data' => $data,
            'record' => $record,
            'action' => $this,
        ]);
    }

    /**
     * Apply `only()` / `except()` to the target Resource's schema,
     * recursively through layout wrappers. Mirrors the filter on
     * SubForm / SubFormRepeater; re-implemented here because Action is
     * not a Schema component and doesn't have access to the field
     * trait's `applyFieldFilter` without its sibling machinery.
     *
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    protected function filterActionComponents(array $components): array
    {
        $only = $this->evaluate($this->only);
        $except = $this->evaluate($this->except);

        if (blank($only) && blank($except)) {
            return $components;
        }

        return $this->applyActionFieldFilter($components, $only, $except);
    }

    /**
     * @param  array<int, Component>  $components
     * @param  array<int, string>|null  $only
     * @param  array<int, string>|null  $except
     * @return array<int, Component>
     */
    protected function applyActionFieldFilter(array $components, ?array $only, ?array $except): array
    {
        $kept = [];

        foreach ($components as $component) {
            if ($component instanceof Field) {
                $name = $component->getName();

                if (filled($only) && ! in_array($name, $only, true)) {
                    continue;
                }

                if (filled($except) && in_array($name, $except, true)) {
                    continue;
                }

                $kept[] = $component;

                continue;
            }

            if (! ($component instanceof Component)) {
                $kept[] = $component;

                continue;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $key => $childSchema) {
                $component->childComponents(
                    $this->applyActionFieldFilter(
                        $childSchema->getComponents(withActions: true, withHidden: true),
                        $only,
                        $except,
                    ),
                    $key,
                );
            }

            $component->clearCachedDefaultChildSchemas();

            $kept[] = $component;
        }

        return $kept;
    }
}
