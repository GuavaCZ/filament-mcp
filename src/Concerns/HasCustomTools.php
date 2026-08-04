<?php

namespace Guava\FilamentMcp\Concerns;

use Laravel\Mcp\Server\Tool;

trait HasCustomTools
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [];

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function getTools(): array
    {
        return $this->tools;
    }
}
