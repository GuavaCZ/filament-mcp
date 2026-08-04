<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;

trait CanDiscoverActions
{
    protected bool | Closure $discoverActions = false;

    public function discoverActions(bool | Closure $condition = true): static
    {
        $this->discoverActions = $condition;

        return $this;
    }

    public function shouldDiscoverActions(): bool
    {
        return (bool) $this->evaluate($this->discoverActions);
    }
}
