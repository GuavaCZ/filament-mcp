---
title: Files
order: 40
---

# Files

A `FileUpload` field accepts two things from an agent: an **upload handle**, or an **https URL** to fetch. It never accepts a filesystem path.

Once resolved, the file is stored using the field's own configuration (`disk()`, `directory()`, `visibility()`) and the attribute is set to the stored path, exactly as filament would have. Reads go the other way: the agent is given a URL, not a path.
