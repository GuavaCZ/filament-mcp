---
title: Fetching a URL
---

# Fetching a URL

When the agent already has a public URL, it can pass it directly and the server downloads it.

That makes your application issue requests on an agent's behalf, which is a server-side request forgery risk. An MCP token holder, or an agent that read a prompt injection in a comment or an email, could point it at your cloud metadata endpoint or an internal admin panel and read the response back out of a record field.

## The default guards

All of them live in `config/filament-mcp.php`:

- URLs resolving to private, loopback, link-local or reserved addresses are refused, and **every redirect hop is re-checked**. A first hop that looks public redirecting to `169.254.169.254` is the classic bypass.
- Only `http` and `https` schemes.
- A byte cap, a request timeout and a redirect limit.
- The mime type is sniffed **from the bytes**, never taken from the `Content-Type` header, because the remote server controls that header and the file may land on a public disk.

## Narrowing it further

Set `allowed_hosts` to restrict fetching to a list of hosts you trust. Doing so also permits internal addresses for those hosts:

```php
'remote_files' => [
    'allowed_hosts' => ['cdn.example.com', '*.trusted-partner.com'],
],
```

Set `enabled` to `false` to turn URL fetching off entirely and require [uploads](01-uploads.md):

```php
'remote_files' => [
    'enabled' => false,
],
```

## Round-tripping

Reading a record gives you a URL. Writing that same URL back leaves the file untouched rather than re-downloading it, so an agent can safely read a record, change one other field and send the whole thing back.
