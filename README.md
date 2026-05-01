# Filament SubForms

Embed another Filament Resource's form inside a parent form as a sub-form — create a parent and its related record(s) in one submission.

## Install

```bash
composer require wezlo/filament-subforms
```

## Usage

### BelongsTo (`SubForm`)

Given `Order belongsTo Client`, embed the Client form inside the Order create page:

```php
use Wezlo\FilamentSubForms\Filament\Fields\SubForm;

SubForm::make('client')
    ->resource(\App\Filament\Resources\ClientResource::class)
```

On submit:

1. The Client is created from the sub-form data (via the target Resource's `CreateRecord` page — see [Lifecycle fidelity](#lifecycle-fidelity)).
2. The new Client's primary key is injected into the Order's `client_id` **before** the Order insert.
3. The Order is inserted — with a valid FK on the first attempt.

Because the FK is set before the Order insert, the foreign-key column can stay `NOT NULL`. The built-in Filament `->relationship()` flow on Group/Section/Fieldset requires the FK to be nullable; `SubForm` removes that constraint.

### HasOne / MorphOne (`SubForm`)

The same `SubForm` field also handles `HasOne` and `MorphOne`. In those cases the parent must be saved first, so the package falls back to Filament's built-in post-save path — the parent is inserted, then the related record is created and associated.

### HasMany (`SubFormRepeater`)

Given `Order hasMany Item`:

```php
use Wezlo\FilamentSubForms\Filament\Fields\SubFormRepeater;

SubFormRepeater::make('items')
    ->resource(\App\Filament\Resources\OrderItemResource::class)
    ->minItems(1)
```

Extends Filament's `Repeater`, so all Repeater methods (`minItems`, `maxItems`, `reorderable`, etc.) work as usual. Each item row renders the target Resource's form schema. New items are created via the target Resource's `CreateRecord` page (same pipeline as `SubForm`), updates and deletions use Repeater's standard semantics.

The parent's foreign key is injected into each new item's data before the insert, so `NOT NULL` FK columns work out of the box — as long as the FK is in the model's `$fillable` (standard Laravel). If you keep the FK off `$fillable`, the package falls back to a post-create `setAttribute($fk, $parent->getKey())->save()`.

When placed in a context without a parent record (e.g. inside a header action's schema on a List page), the Repeater's save pipeline becomes a no-op — the item data stays in `$data[$name]` for the caller's own handler to persist.

### Standalone Action (`FormAction`)

Embed a Resource's full form inside an action modal — useful for bulk creation, export builders, or any flow where you want the Resource's schema without navigating to the Create page:

```php
use Wezlo\FilamentSubForms\Filament\Actions\FormAction;

FormAction::make('create_client')
    ->resource(\App\Filament\Resources\ClientResource::class)
    ->operation('create')          // optional, defaults to 'create'
    ->except(['internal_notes'])
    ->beforeSave(function (array $data): array {
        // Optional: mutate data before the record is persisted. Return the
        // (possibly modified) array. Return anything else to leave it as-is.
        return $data;
    })
    ->afterSave(function (\Illuminate\Database\Eloquent\Model $record, array $data): void {
        // Optional: fires after the record is persisted AND nested
        // sub-forms have saved. `$record` is the freshly-created model.
    });
```

On submit: `beforeSave` → target Resource's `CreateRecord` page runs (same trait / replay / Eloquent pipeline as `SubForm`) → the action's own schema walks its components for `saveRelationships()` so nested `SubFormRepeater` / `SubForm` fields inside the target's form persist against the new record → `afterSave`.

Notes specific to `FormAction`:

- `->only([...])` / `->except([...])` work the same way as on the fields, with recursive descent.
- `->operation(string | Closure)` sets the Schema operation inside the modal so components can use `->visibleOn('create')`, `->hiddenOn('edit')`, and any operation-aware field logic. Defaults to `'create'` — the typical "create a record via action" use case — so you rarely need to set it explicitly.
- Because the action's schema holds the live user input, nested sub-forms in the target Resource's form persist as part of the action's submit — e.g. creating a Client plus its Orders in a single modal works out of the box.
- The same cycle-detection, model-binding, and Action preservation logic applies to the embedded schema.

### Picking fields

Include / exclude fields from the target Resource's schema:

```php
SubForm::make('client')
    ->resource(ClientResource::class)
    ->only(['name', 'email'])
// or
    ->except(['internal_notes'])
```

`only` / `except` match:

- **Fields** by `getName()` — TextInput, Select, Repeater, etc.
- **SubForm components** by the relationship name passed to `SubForm::make('relationship')`.

Matching is recursive: fields and sub-forms buried inside layout wrappers (`Section`, `Fieldset`, `Grid`, `Group`, `Tabs`, …) are filtered just like top-level ones. Wrappers themselves are preserved — an empty wrapper renders as an empty section.

When a sub-form is excluded, the excluded component is **also** stripped from the target Resource's `CreateRecord` page's own form before that page's `saveRelationships()` runs — so the nested sub-form's save hook doesn't fire against data it was never given.

## Lifecycle fidelity

By default, the package drives the child record creation through the target Resource's `CreateRecord` page using one of three paths:

1. **Trait path** — the target's `CreateRecord` page uses the `IsSubFormPage` trait. The package calls `$page->createAsSubform($data, $except)`, which runs the page's lifecycle against `$data`:

    - `mutateFormDataBeforeCreate($data)`
    - `beforeCreate` hook
    - `handleRecordCreation($data)` (tenant association, custom persistence, …)
    - `saveRelationships` (with excluded components stripped, so unrelated sub-forms don't fire)
    - `afterCreate` hook
    - `RecordCreated` / `RecordSaved` events

    The trait suppresses page-level side-effects that would break the host submit: `redirect`, the "Created" notification, begin/commit/rollback transaction, `rememberData`, `authorizeAccess`.

2. **Replay path** — the target's page does **not** use the trait. The package replays the same ordering inline via `Closure::bind` so user overrides of `mutateFormDataBeforeCreate`, `handleRecordCreation`, and the create hooks still fire. `saveRelationships` runs on the **host** schema (the parent form, or the action's own schema for `FormAction`), not on the target page's form — the host holds the live state, the target page's form doesn't.

3. **Eloquent fallback** — the target Resource has no registered `create` page. The package does a plain `new Model; fill; save()`.

### Opting into full fidelity

Add the `IsSubFormPage` trait to any `CreateRecord` page you want to use as a sub-form target:

```php
use Filament\Resources\Pages\CreateRecord;
use Wezlo\FilamentSubForms\Filament\Pages\Concerns\IsSubFormPage;

class CreateClient extends CreateRecord
{
    use IsSubFormPage;

    protected static string $resource = ClientResource::class;

    // You can branch on $this->isSubform inside any override to adjust
    // behaviour when running as a nested creation.
}
```

This is what you need for:

- **Multi-tenancy** — Filament's panel-tenant association lives in your page's `handleRecordCreation` (or a trait/observer it hooks into). The trait path runs that method, so `tenant_id` is set the same way it would be on a direct Create.
- **Custom `beforeCreate` / `afterCreate` hooks** on the target's page.
- **`RecordCreated` / `RecordSaved` events** fired from the target's perspective.

## Cycle detection

If two Resources' forms embed each other (e.g. `Order` has `SubForm(client) → ClientResource` and `Client` has `SubFormRepeater(orders) → OrderResource`), any nested sub-form that would reintroduce a model already in scope is **stripped from the tree**.

A sub-form is considered cyclic when its target Resource's model matches the model of the current page's form or of any ancestor sub-form. Cyclic sub-forms are removed recursively — including ones buried inside layout wrappers — so there is no empty component, no Add button, no validation rules. The outer sub-form still renders normally.

## Configuration

A publishable config file ships with the package. Publish with:

```bash
php artisan vendor:publish --tag="filament-subforms-config"
```

Current options are documented in `config/filament-subforms.php`.

## Notes

- Create-only for v1. Editing an existing related record via the sub-form is not yet wired; use a normal Resource Edit page for that.
- The relationship name passed to `make()` must match a real Eloquent relationship on the parent model (`Order::client()`, `Order::items()`, etc.).
- The target Resource's `form()` method is called at render time. If it does non-trivial work, be aware that it runs every time the parent form hydrates.
- Actions defined in the target Resource's form (top-level or nested inside layout wrappers) are preserved in the sub-form.
