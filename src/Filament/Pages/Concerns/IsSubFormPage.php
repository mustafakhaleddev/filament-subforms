<?php

namespace Wezlo\FilamentSubForms\Filament\Pages\Concerns;

use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Resources\Events\RecordCreated;
use Filament\Resources\Events\RecordSaved;
use Filament\Schemas\Components\Component;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Apply to a Resource's `CreateRecord` page to make it safe to drive from
 * a SubForm during the host form's submit.
 *
 * With this trait present, SubForm invokes the page's full `create()`
 * flow — every lifecycle hook (`beforeCreate`, `afterCreate`), every
 * event (`RecordCreated`, `RecordSaved`), and any user overrides — while
 * the trait neutralises the page-level side-effects that would otherwise
 * break the host submit:
 *
 *  - redirect (would terminate the host response mid-submit)
 *  - "Created" notification (wrong message for the host action)
 *  - begin/commit/rollback transaction (the host already owns one)
 *  - `rememberData` (writes irrelevant state to the session)
 *  - `authorizeAccess` (the host already authorised the user)
 *
 * Custom logic in the page can read `$this->isSubform` to branch on
 * whether it's running as a nested creation.
 */
trait IsSubFormPage
{
    /**
     * Set to true by SubForm while driving this page. Any override in
     * the user's page class is free to read it and adjust behaviour.
     */
    public bool $isSubform = false;

    /**
     * Drive the lifecycle parts of the page's `create()` flow against
     * the supplied `$data` and return the created record.
     *
     * We intentionally do NOT call `$this->create()`. `create()` begins
     * with `$this->form->getState()`, which validates the target
     * Resource's FULL form schema — including any nested sub-forms the
     * target itself defines. Those would demand data we don't have and
     * throw "field is required" errors for unrelated Resources. The
     * host form already validated `$data` before we got here.
     *
     * Every user override of `mutateFormDataBeforeCreate`,
     * `handleRecordCreation`, or the `beforeCreate` / `afterCreate`
     * hooks still fires — we call them on `$this` (the real page
     * instance), not via reflection or binding.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAsSubform(array $data, array $except = []): ?Model
    {
        $wasSubform = $this->isSubform;
        $this->isSubform = true;

        if ($this->isCreating) {

            return null;
        }
        $this->isCreating = true;

        $this->authorizeAccess();

        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeCreate($data);
            $this->callHook('beforeCreate');

            $this->record = $this->handleRecordCreation($data);

            // Strip any component whose name matches `$except` from the
            // target page's form before walking it for relationship
            // saves. The host sub-form excluded these fields from the
            // user's input, so there's no data for them; letting
            // `saveRelationships()` touch them would either persist a
            // blank record or throw a "field required" error for data
            // we don't have.
            if (filled($except)) {
                $this->stripNamedComponentsFromForm($except);
            }

            $this->form->model($this->getRecord())->saveRelationships();

            $this->callHook('afterCreate');

            Event::dispatch(RecordCreated::class, ['record' => $this->record, 'data' => $data, 'page' => $this]);
            Event::dispatch(RecordSaved::class, ['record' => $this->record, 'data' => $data, 'page' => $this]);
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction() ?
                $this->rollBackDatabaseTransaction() :
                $this->commitDatabaseTransaction();

            $this->isCreating = false;

            return null;
        } catch (\Exception $e) {
            dd($e);
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            $this->isCreating = false;

            throw $exception;
        } finally {
            $this->commitDatabaseTransaction();

            $this->rememberData();

            $this->getCreatedNotification()?->send();

            $this->isSubform = $wasSubform;

            return $this->getRecord();
        }

    }

    protected function authorizeAccess(): void
    {
        if ($this->isSubform) {
            return;
        }

        parent::authorizeAccess();
    }

    protected function beginDatabaseTransaction(): void
    {
        if ($this->isSubform) {
            return;
        }

        parent::beginDatabaseTransaction();
    }

    protected function commitDatabaseTransaction(): void
    {
        if ($this->isSubform) {
            return;
        }

        parent::commitDatabaseTransaction();
    }

    protected function rollBackDatabaseTransaction(): void
    {
        if ($this->isSubform) {
            return;
        }

        parent::rollBackDatabaseTransaction();
    }

    protected function rememberData(): void
    {
        if ($this->isSubform) {
            return;
        }

        parent::rememberData();
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->isSubform) {
            return null;
        }

        return parent::getCreatedNotification();
    }

    public function redirect($url, $navigate = false)
    {
        if ($this->isSubform) {
            return;
        }

        parent::redirect($url, $navigate);
    }

    /**
     * Remove every component whose name matches `$names` from this page's
     * form, recursively (so a sub-form nested inside a Section is also
     * matched). Called before `saveRelationships()` so excluded sub-forms
     * don't fire their save hooks against data we don't have.
     *
     * @param  array<int, string>  $names
     */
    protected function stripNamedComponentsFromForm(array $names): void
    {
        $components = $this->form->getComponents(withActions: true, withHidden: true);

        $this->form->components($this->stripNamedComponents($components, $names));
    }

    /**
     * @param  array<int, mixed>  $components
     * @param  array<int, string>  $names
     * @return array<int, mixed>
     */
    protected function stripNamedComponents(array $components, array $names): array
    {
        $kept = [];

        foreach ($components as $component) {
            $name = $this->resolveSubformComponentName($component);

            if ($name !== null && in_array($name, $names, true)) {
                continue;
            }

            if ($component instanceof Component) {
                foreach ($component->getChildSchemas(withHidden: true) as $key => $childSchema) {
                    $component->childComponents(
                        $this->stripNamedComponents(
                            $childSchema->getComponents(withActions: true, withHidden: true),
                            $names,
                        ),
                        $key,
                    );
                }

                $component->clearCachedDefaultChildSchemas();
            }

            $kept[] = $component;
        }

        return $kept;
    }

    /**
     * Field components are matched by `getName()`. SubForm components
     * (extending Group rather than Field) are matched by the
     * relationship name supplied to `SubForm::make('relationship')`.
     */
    protected function resolveSubformComponentName(mixed $component): ?string
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
