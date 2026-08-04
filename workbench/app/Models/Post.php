<?php

namespace Workbench\App\Models;

use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\App\Models\Concerns\HasTranslations;
use Workbench\Database\Factories\PostFactory;

class Post extends Model implements HasRichContent
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasTranslations;
    use InteractsWithRichContent;

    protected $guarded = [];

    protected $hidden = ['internal_notes'];

    /**
     * `intro` is plain text and `body_i18n` is a TipTap document, so between
     * them they cover both a mapper that transforms a translatable value and
     * one that does not.
     *
     * @var array<int, string>
     */
    public array $translatable = [
        'intro',
        'body_i18n',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'body_json' => 'array',
            'gallery' => 'array',
            'links' => 'array',
            'annotations' => 'array',
            'blocks' => 'array',
            'meta' => 'array',
            'intro' => 'array',
            'body_i18n' => 'array',
        ];
    }

    /**
     * `body_json` is registered as JSON rich content on the model rather than
     * with `->json()` on the field, which is the case the mapper has to detect
     * without a mounted Livewire component.
     */
    protected function setUpRichContent(): void
    {
        $this->registerRichContent('body_json')->json();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
