<?php

namespace Guava\FilamentMcp\Support;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * Identifies form fields whose value is a set of related records rather than
 * an attribute on the record itself.
 *
 * Filament marks a multiple relationship `Select` as `dehydrated(false)` and
 * writes it in `saveRelationships()` after the record is saved. Nothing on the
 * MCP write path calls that, so such a field has to be recognised, kept out of
 * `fill()`, and synced separately once the record has a key.
 *
 * Only `BelongsToMany` is treated as syncable. Syncing a `HasMany` would mean
 * nulling out or deleting the records it detaches, which is not a decision this
 * package makes for the app.
 */
class RelationshipField
{
    /**
     * The relation behind a syncable field, or null if the field is not one.
     *
     * The returned relation is bound to the schema's model instance, so it is
     * only good for metadata such as the related class and key name. Syncing goes
     * through the saved record's own relation.
     */
    public static function syncable(Field $field): ?BelongsToMany
    {
        if (! $field instanceof Select) {
            return null;
        }

        try {
            if (! $field->isMultiple() || blank($field->getRelationshipName())) {
                return null;
            }

            $relation = $field->getRelationship();
        } catch (Throwable) {
            // Relationship resolution evaluates closures that may want state a
            // headless schema does not have. Treat it as not syncable.
            return null;
        }

        return $relation instanceof BelongsToMany ? $relation : null;
    }

    public static function isSyncable(Field $field): bool
    {
        return static::syncable($field) !== null;
    }

    /**
     * The related model behind a singular relationship select, or null when the
     * field is not one.
     *
     * Such a field writes a foreign key straight onto the record rather than being
     * synced, so it never reaches `syncable()`. In the panel the closed option list
     * checks the key; headlessly there is no option list, so the key is verified
     * against the related model itself.
     *
     * `MorphTo` is excluded, because its related class comes from a sibling type
     * column rather than the relation, so this would return whichever model the
     * relation defaults to.
     */
    public static function singular(Field $field): ?Model
    {
        if (! $field instanceof Select) {
            return null;
        }

        try {
            if ($field->isMultiple() || blank($field->getRelationshipName())) {
                return null;
            }

            $relation = $field->getRelationship();
        } catch (Throwable) {
            // As in syncable(). A relationship that cannot be resolved headlessly
            // leaves the value to the database's own foreign key constraint.
            return null;
        }

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
            return null;
        }

        return $relation->getRelated();
    }
}
