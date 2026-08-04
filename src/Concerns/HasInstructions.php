<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;

trait HasInstructions
{
    protected string | Closure | null $instructions = null;

    public function instructions(string | Closure $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->evaluate($this->instructions);
    }
}
