<?php

namespace Workbench\App\Policies;

use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Swapped in with Gate::policy() to stand for an app that hides records from a
 * user entirely.
 */
class DenyEverythingPostPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Post $post): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Post $post): bool
    {
        return false;
    }

    public function delete(User $user, Post $post): bool
    {
        return false;
    }
}
