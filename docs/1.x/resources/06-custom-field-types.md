---
title: Custom field types
---

# Custom field types

Teach the package about a component it doesn't know, or replace a built-in mapper:

```php
McpPlugin::make()->fieldMapper(ColorPicker::class, ColorPickerMapper::class)
```

The registration belongs to that panel, so two panels can map the same component differently. To map one everywhere, register it on the `FieldMapperRegistry` singleton directly.

A mapper implements `FieldMapper` and, optionally:

- `TransformsWrites`, to convert an incoming value before it reaches the model
- `TransformsReads`, to convert a stored value on the way back out
- `ProvidesValidationRules`, to replace the field's own rules when they describe Livewire's state shape rather than what an agent can send

Mappers are resolved by walking up the component's class hierarchy, so registering a base class covers its subclasses.

> [!NOTE]
> Without a mapper, an unknown component is exposed as a described string rather than being dropped. That's usually fine for a scalar, and usually wrong for anything with structure, since the agent gets no schema to fill in.
