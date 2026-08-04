<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;

trait HasServerName
{
    protected string | Closure | null $serverName = null;

    public function serverName(string | Closure $name): static
    {
        $this->serverName = $name;

        return $this;
    }

    public function getServerName(): ?string
    {
        return $this->evaluate($this->serverName);
    }
}
