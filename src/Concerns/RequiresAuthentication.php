<?php

namespace Guava\FilamentMcp\Concerns;

use Illuminate\Http\Request;

/**
 * Hides a custom tool from unauthenticated callers.
 *
 * Custom tools are plain `laravel/mcp` tools, so nothing authorizes them, and on
 * a server with any `public()` resource they are listed to guests like any other
 * tool. Add this to a tool that should not exist for an anonymous caller, on top
 * of (never instead of) the authorization checks in `handle()`, since this only
 * controls listing and dispatch.
 *
 * A tool defining its own `shouldRegister()` overrides this, since a class-defined
 * method takes precedence over a trait's.
 */
trait RequiresAuthentication
{
    public function shouldRegister(Request $request): bool
    {
        return $request->user() !== null;
    }
}
