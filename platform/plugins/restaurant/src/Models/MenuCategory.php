<?php

namespace Botble\Restaurant\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Models\BaseModel;
use Botble\Restaurant\Enums\MenuStatusEnum;
use Botble\Slug\Models\Slug;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MenuCategory extends BaseModel
{
    protected $table = 'restaurant_menu_categories';

    protected $fillable = [
        'name',
        'description',
        'image',
        'order',
        'status',
        'is_featured',
    ];

    protected $casts = [
        'status' => MenuStatusEnum::class,
        'name' => SafeContent::class,
        'description' => SafeContent::class,
        'is_featured' => 'boolean',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id');
    }

    public function publishedMenuItems(): HasMany
    {
        return $this->menuItems()->where('status', MenuStatusEnum::PUBLISHED);
    }

    public function items(): HasMany
    {
        return $this->menuItems();
    }

    protected static function booted(): void
    {
        static::deleting(function ($category): void {
            $category->menuItems()->delete();
        });
    }

    public function slugable(): MorphOne
    {
        return $this->morphOne(Slug::class, 'reference');
    }
}
