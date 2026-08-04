<?php

namespace Guava\FilamentMcp\Concerns;

trait HasPath
{
    protected ?string $path = null;

    public function path(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
