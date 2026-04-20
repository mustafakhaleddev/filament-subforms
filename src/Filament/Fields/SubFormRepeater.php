<?php

namespace Wezlo\FilamentSubForms\Filament\Fields;

use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class SubFormRepeater extends Repeater
{
    use Concerns\CreatesRelatedRecordViaPage;
    use Concerns\ResolvesResourceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relationship();
        $this->visibleOn('create');

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

            // Bind the target's model to the Schema handed to `form()`.
            // Without this, any `Repeater::make(...)->relationship(...)`
            // inside the target's form would walk up looking for a
            // model, find none, and throw
            // "Call to a member function hasAttribute() on null".
            /** @var class-string<resource> $resource */
            $schema = $resource::form(
                Schema::make($component->getLivewire())->model($resource::getModel())
            );

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
        // Replace Repeater's default save-relationships callback so each
        // NEW item is created via the target Resource's CreateRecord page
        // (preferred trait path → replay path → Eloquent fallback — see
        // CreatesRelatedRecordViaPage). Update of an already-persisted
        // item and deletion of removed items keep Repeater's standard
        // semantics because those paths don't need CreateRecord.
        $this->saveRelationshipsUsing(static function (SubFormRepeater $component, HasSchemas $livewire, ?array $state): void {
            $component->saveRelationshipsViaPage(is_array($state) ? $state : []);
        });
    }

    /**
     * Persist Repeater items. New items go through the target Resource's
     * CreateRecord page; existing items are updated in place; removed
     * items are deleted. Mirrors Repeater::relationship()'s built-in
     * save semantics but swaps the create branch.
     *
     * @param  array<array-key, array<string, mixed>>  $state
     */
    public function saveRelationshipsViaPage(array $state): void
    {
        $relationship = $this->getRelationship();
        $existingRecords = $this->getCachedExistingRecords();
        $relatedKeyName = $relationship->getRelated()->getKeyName();
        $parentKey = $this->getRecord()?->getKey();
        $foreignKeyName = $relationship->getForeignKeyName();

        $recordsToDelete = [];

        foreach ($existingRecords->pluck($relatedKeyName) as $keyToCheckForDeletion) {
            if (array_key_exists("record-{$keyToCheckForDeletion}", $state)) {
                continue;
            }

            $recordsToDelete[] = $keyToCheckForDeletion;
            $existingRecords->forget("record-{$keyToCheckForDeletion}");
        }

        if (filled($recordsToDelete)) {
            $relationship
                ->whereKey($recordsToDelete)
                ->get()
                ->each(function (Model $record): void {
                    $record->delete();
                    $this->callAfterDelete($record);
                });
        }

        $itemOrder = 1;
        $orderColumn = $this->getOrderColumn();

        foreach ($this->getItems() as $itemKey => $item) {
            $itemData = $item->getState(shouldCallHooksBefore: false);

            if ($orderColumn) {
                $itemData[$orderColumn] = $itemOrder;
                $itemOrder++;
            }

            if ($record = ($existingRecords[$itemKey] ?? null)) {
                $itemData = $this->mutateRelationshipDataBeforeSave($itemData, record: $record);
                if ($itemData === null) {
                    continue;
                }

                $record->fill($itemData)->save();
                $this->callAfterUpdate($itemData, $record);

                continue;
            }

            $itemData = $this->mutateRelationshipDataBeforeCreate($itemData);

            if ($itemData === null) {
                continue;
            }

            // Inject the FK so the item INSERT lands with the parent link
            // already set — required when the FK column is NOT NULL.
            // Works when the FK is in `$fillable` (standard Laravel
            // practice). If it isn't, we force-set it after creation.
            if (filled($foreignKeyName) && filled($parentKey)) {
                $itemData[$foreignKeyName] = $parentKey;
            }

            $record = $this->createRelatedRecord($itemData);

            // Safety net: if the target Resource's CreateRecord page
            // stripped the FK (e.g. in mutateFormDataBeforeCreate or a
            // non-fillable column), associate after the fact.
            if (filled($foreignKeyName) && filled($parentKey) && blank($record->getAttribute($foreignKeyName))) {
                $record->setAttribute($foreignKeyName, $parentKey)->save();
            }

            $item->model($record)->saveRelationships();
            $this->callAfterCreate($itemData, $record);
            $existingRecords->push($record);
        }

        $this->getRecord()->setRelation($this->getRelationshipName(), $existingRecords);
    }
}
