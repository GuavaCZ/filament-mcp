<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;

trait CanServeLocally
{
    protected bool | Closure $local = false;

    public function local(bool | Closure $condition = true): static
    {
        $this->local = $condition;

        return $this;
    }

    public function shouldServeLocally(): bool
    {
        return (bool) $this->evaluate($this->local);
    }
}
