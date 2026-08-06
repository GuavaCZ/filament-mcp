---
title: Actions
order: 30
---

# Actions

Beyond CRUD, you can expose your existing filament actions as tools. A marked action becomes a callable tool executed headlessly through the real closure, so the button and the tool cannot drift apart.

The following pages cover bridging an action, collecting input through its modal form, keeping the operation itself out of the closure, and writing a custom tool for the cases the bridge doesn't reach.

> [!IMPORTANT]
> Marked actions are only picked up when `->discoverActions()` is enabled on the plugin.
