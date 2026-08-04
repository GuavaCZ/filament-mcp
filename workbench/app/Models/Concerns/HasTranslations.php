<?php

namespace Workbench\App\Models\Concerns;

/**
 * The smallest thing the package's translatable handling recognises.
 *
 * `spatie/laravel-translatable` is not a dependency, so the transposer duck-types
 * `isTranslatableAttribute()` and `getTranslations()`. This mirrors the two
 * behaviours that matter: a translatable attribute serialises to a
 * `locale => value` map, and an array assigned to one is taken to be that map
 * rather than the value.
 */
trait HasTranslations
{
    /**
     * @return array<int, string>
     */
    public function getTranslatableAttributes(): array
    {
        return $this->translatable ?? [];
    }

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->getTranslatableAttributes());
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranslations(string $key): array
    {
        $value = $this->getAttribute($key);

        return is_array($value) ? $value : [];
    }

    public function getTranslation(string $key, string $locale): mixed
    {
        return $this->getTranslations($key)[$locale] ?? null;
    }
}
