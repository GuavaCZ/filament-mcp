---
title: Field visibility
---

# Field visibility

The fields a record is serialised with default to the resource's form fields, and a field the panel would not render is not serialised. So `->visible(fn ($user) => $user->isAdmin())` keeps that column out of `get_*` and `list_*` for everyone else. A field that is merely `->disabled()` stays readable, because disabled means "you may not write this", not "you may not see it".

One limit worth knowing: visibility is evaluated **without a record**, so a closure that branches on the *record* is not a read control:

```php
// Not a read control. Evaluated with no record.
->visible(fn ($record) => $record->owner_id === auth()->id())
```

Use `hiddenFields()`, `fields()` or `query()` for anything that depends on which row it is:

```php
McpResource::make(UserResource::class)
    ->hiddenFields(['two_factor_secret'])
```

## Sorting and searching are narrowed too

A field kept out of the output is also kept out of `list_*`'s `sort` and `search`. Neither prints a value, and both report on one anyway: an ordering is a comparison against every other row, and `search` matched against a hidden column would answer "does it contain this?" once per character.

Sorting by such a field is refused, and it's dropped from the searched set even when the resource names it in `getGloballySearchableAttributes()`. `hiddenFields()` is a deny-list, so it wins there too.

This is evaluated per caller, so an admin still sorts and searches by a field only admins may read.
