<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that guards its attributes, unlike the wide-open Post.
 *
 * `secret` is exposed by the form but absent from $fillable, the shape in which
 * a write would otherwise succeed while discarding data.
 */
class GuardedNote extends Model
{
    protected $fillable = ['title'];
}
