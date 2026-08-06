---
title: Reading and listing
---

# Reading and listing

## What a read contains

The output contains the primary key, the form's field names and timestamps, filtered by `fields()` / `hiddenFields()` and your model's `$hidden`.

Fields the panel would not render are left out too, so a `->visible(fn ($user) => $user->isAdmin())` field is not serialised for anyone else. That check runs without a record though, so record-dependent visibility is not a read control. See [field visibility](../security/07-field-visibility.md).

## Listing, searching and sorting

`list_*` takes `page`, `per_page`, `search`, `sort` and `direction`. `per_page` is capped at `recordsPerPage()`.

`search` matches against the resource's `getGloballySearchableAttributes()`. When a resource declares none, it falls back to the form's plain text fields, meaning `TextInput` and `Textarea` on attributes your model does not cast. Json, boolean, date and file columns are deliberately left out: matching them with `LIKE` means nothing on MySQL and is an error on Postgres. Declare `getGloballySearchableAttributes()` when you want something more specific.

`sort` accepts the primary key, any non-nested form field and the timestamp columns. Anything else is refused, with the list of what is allowed.

Both `search` and `sort` are narrowed to the fields the caller may read, so neither reports on a value that `get_*` would have withheld. See [field visibility](../security/07-field-visibility.md).

> [!NOTE]
> `list_*` checks `viewAny` while `get_*` checks `view` on the record it was handed. That's the panel's own split, but an agent pages through everything it can reach rather than scrolling one screen. If your per-record rule lives in `view`, put it in `->query()` as well, see [listing versus reading](../security/03-authorization.md#listing-versus-reading).
