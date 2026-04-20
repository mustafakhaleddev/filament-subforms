<?php

namespace Wezlo\FilamentSubForms\Filament\Fields\Concerns;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;

trait ResolvesResourceSchema
{
    protected string|Closure|null $resource = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $only = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $except = null;

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

    /**
     * Return true if the target Resource's model is already bound to any
     * ancestor container in the component tree — either the top-level
     * page's form (e.g. CreateClient's form is bound to Client) or an
     * intermediate SubForm/SubFormRepeater's child schema.
     *
     * That means embedding the Resource here would reintroduce a model
     * already in scope — a cycle (Client → Order → Client, or the
     * mirrored Order → Client → Order).
     *
     * @param  class-string  $resource
     */
    protected function hasAncestorEmbeddingResource(string $resource): bool
    {
        if (! is_subclass_of($resource, \Filament\Resources\Resource::class)) {
            return false;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        $targetModel = $resource::getModel();

        if (blank($targetModel)) {
            return false;
        }

        return in_array($targetModel, $this->collectAncestorModels(), true);
    }

    /**
     * Collect every model class-string already bound in the component tree
     * from this component's own container up to the root.
     *
     * @return array<int, class-string>
     */
    protected function collectAncestorModels(): array
    {
        $models = [];

        $container = $this->getContainer();

        while ($container !== null) {
            $model = $container->getModel();

            if (filled($model)) {
                $models[] = $model;
            }

            $parent = $container->getParentComponent();

            if ($parent === null) {
                break;
            }

            $container = $parent->getContainer();
        }

        return $models;
    }

    /**
     * Drop any sub-form component whose target Resource's model appears
     * in `$blockedModels`. Descends recursively into layout wrappers
     * (Section, Fieldset, Grid, Group, Tabs, …) so a cyclic sub-form
     * nested inside a Section is removed just like a top-level one.
     *
     * This is how we break mutual-reference cycles: when the outer
     * SubForm unwraps its target Resource's schema, any nested
     * SubForm / SubFormRepeater that would reintroduce an in-scope
     * model is removed from the returned tree entirely — no empty
     * wrapper, Add button, or placeholder left behind.
     *
     * @param  array<int, Component>  $components
     * @param  array<int, class-string>  $blockedModels
     * @return array<int, Component>
     */
    protected function stripCyclicSubForms(array $components, array $blockedModels): array
    {
        if (blank($blockedModels)) {
            return $components;
        }

        $kept = [];

        foreach ($components as $component) {
            if (! ($component instanceof Component)) {
                $kept[] = $component;

                continue;
            }

            if ($this->isCyclicSubForm($component, $blockedModels)) {
                continue;
            }

            // Layout wrapper: recurse into every child schema it owns
            // and replace its children with the stripped set.
            foreach ($component->getChildSchemas(withHidden: true) as $key => $childSchema) {
                $strippedChildren = $this->stripCyclicSubForms(
                    $childSchema->getComponents(withActions: true, withHidden: true),
                    $blockedModels,
                );

                $component->childComponents($strippedChildren, $key);
            }

            $component->clearCachedDefaultChildSchemas();

            $kept[] = $component;
        }

        return $kept;
    }

    /**
     * @param  array<int, class-string>  $blockedModels
     */
    protected function isCyclicSubForm(Component $component, array $blockedModels): bool
    {
        if (! in_array(ResolvesResourceSchema::class, class_uses_recursive($component), true)) {
            return false;
        }

        /** @var object{getResource: callable(): ?string} $component */
        $resource = $component->getResource();

        if (! is_string($resource) || ! is_subclass_of($resource, \Filament\Resources\Resource::class)) {
            return false;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        $targetModel = $resource::getModel();

        if (blank($targetModel)) {
            return false;
        }

        return in_array($targetModel, $blockedModels, true);
    }

    /**
     * Filter the components returned from the target Resource's schema
     * against `only()` / `except()`. Descends recursively into layout
     * wrappers (Section, Fieldset, Grid, Group, Tabs…) so a field
     * nested inside a Section is filtered just like a top-level field.
     *
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    protected function filterResourceComponents(array $components): array
    {
        $only = $this->evaluate($this->only);
        $except = $this->evaluate($this->except);

        if (blank($only) && blank($except)) {
            return $components;
        }

        return $this->applyFieldFilter($components, $only, $except);
    }

    /**
     * @param  array<int, Component>  $components
     * @param  array<int, string>|null  $only
     * @param  array<int, string>|null  $except
     * @return array<int, Component>
     */
    protected function applyFieldFilter(array $components, ?array $only, ?array $except): array
    {
        $kept = [];

        foreach ($components as $component) {
            $name = $this->resolveFilterableName($component);

            if ($name !== null) {
                if (filled($only) && ! in_array($name, $only, true)) {
                    continue;
                }

                if (filled($except) && in_array($name, $except, true)) {
                    continue;
                }

                $kept[] = $component;

                continue;
            }

            // Non-Component items (Actions, ActionGroups, strings, Htmlable)
            // pass through untouched — they have no child schema to recurse
            // into and aren't named fields to filter against.
            if (! ($component instanceof Component)) {
                $kept[] = $component;

                continue;
            }

            // Layout wrapper (Section, Fieldset, Grid, Group, Tabs, …):
            // recurse into every child schema it owns and replace those
            // child components with the filtered set.
            foreach ($component->getChildSchemas(withHidden: true) as $key => $childSchema) {
                $filteredChildren = $this->applyFieldFilter(
                    $childSchema->getComponents(withActions: true, withHidden: true),
                    $only,
                    $except,
                );

                $component->childComponents($filteredChildren, $key);
            }

            $component->clearCachedDefaultChildSchemas();

            $kept[] = $component;
        }

        return $kept;
    }

    /**
     * Return the name a component should be matched against for
     * `only()` / `except()`, or null if the component is a layout
     * wrapper / non-component that should fall through to recursion.
     *
     * Handles:
     *  - Filament `Field` subclasses (TextInput, Select, Repeater, …)
     *    — which includes our `SubFormRepeater`.
     *  - `SubForm`, which extends `Group` (a layout container), not
     *    `Field`. Without this branch, `->except(['client'])` wouldn't
     *    match it — it'd be treated as a layout wrapper. Using the
     *    relationship name keeps the user-facing API consistent.
     */
    protected function resolveFilterableName(mixed $component): ?string
    {
        if ($component instanceof Field) {
            return $component->getName();
        }

        if ($component instanceof Component && method_exists($component, 'getSubFormRelationship')) {
            /** @var object{getSubFormRelationship: callable(): string} $component */
            return $component->getSubFormRelationship();
        }

        return null;
    }
}
