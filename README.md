# Filament SubForms

Embed another Filament Resource's form inside a parent form as a sub-form — create a parent and its related record(s) in one submission.

## Install

Local development only; the package is registered as a path repository in the root `composer.json`.

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

1. The Client is created from the sub-form data.
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

Extends Filament's `Repeater`, so all Repeater methods (`minItems`, `maxItems`, `reorderable`, etc.) work as usual. Each item row renders the target Resource's form schema.

### Picking fields

Include / exclude top-level fields from the target Resource's schema:

```php
SubForm::make('client')
    ->resource(ClientResource::class)
    ->only(['name', 'email'])
// or
    ->except(['internal_notes'])
```

`only` / `except` match field names anywhere in the target Resource's schema — fields nested inside layout wrappers (`Section`, `Fieldset`, `Grid`, `Group`, `Tabs`, …) are filtered just like top-level fields. The wrappers themselves are preserved; an empty wrapper will render as an empty section after filtering.

## Cycle detection

If two Resources' forms embed each other (e.g. `Order` has `SubForm(client) → ClientResource` and `Client` has `SubFormRepeater(orders) → OrderResource`), an inner sub-form that would reintroduce a model already in scope is **stripped from the tree**.

Concretely: when a sub-form's target Resource's model matches the model of the current page's form or of any ancestor sub-form, the nested sub-form is not added as a child component. No wrapper, no Add button, no validation rules — it simply does not render. The outer sub-form still renders normally.

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
