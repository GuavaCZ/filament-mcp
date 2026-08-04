<?php

namespace Workbench\App\Policies;

use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The record is readable but nothing may be changed through the CRUD tools, so
 * an action's own policy mapping is the only thing left standing between the
 * agent and a write.
 */
class ViewOnlyPostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return true;
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
