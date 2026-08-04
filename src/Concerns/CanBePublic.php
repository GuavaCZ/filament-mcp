<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;

trait CanBePublic
{
    protected bool | Closure $public = false;

    public function public(bool | Closure $condition = true): static
    {
        $this->public = $condition;

        return $this;
    }

    public function isPublic(): bool
    {
        return (bool) $this->evaluate($this->public);
    }
}
