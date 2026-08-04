<?php

namespace Guava\FilamentMcp\Concerns;

use Laravel\Mcp\Server\Prompt;

trait HasPrompts
{
    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [];

    /**
     * @param  array<int, class-string<Prompt>>  $prompts
     */
    public function prompts(array $prompts): static
    {
        $this->prompts = $prompts;

        return $this;
    }

    /**
     * @return array<int, class-string<Prompt>>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }
}
