<?php

namespace Guava\FilamentMcp\Concerns;

trait HasMiddleware
{
    /**
     * @var array<int, class-string|string>
     */
    protected array $middleware = [];

    /**
     * @param  array<int, class-string|string>  $middleware
     */
    public function middleware(array $middleware): static
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * @return array<int, class-string|string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
