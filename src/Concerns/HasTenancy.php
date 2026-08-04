<?php

namespace Guava\FilamentMcp\Concerns;

trait HasTenancy
{
    protected bool $tenantInPath = false;

    public function tenantInPath(bool $condition = true): static
    {
        $this->tenantInPath = $condition;

        return $this;
    }

    public function isTenantInPath(): bool
    {
        return $this->tenantInPath;
    }
}
