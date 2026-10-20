<?php

namespace Botble\Restaurant\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Models\BaseModel;
use Botble\Restaurant\Enums\MenuStatusEnum;
use Botble\Slug\Models\Slug;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MenuItem extends BaseModel
{
    protected $table = 'restaurant_menu_items';

    protected $fillable = [
        'name',
        'description',
        'price',
        'sale_price',
        'image',
        'category_id',
        'is_featured',
        'is_vegetarian',
        'is_vegan',
        'is_gluten_free',
        'calories',
        'ingredients',
        'order',
        'status',
    ];

    protected $casts = [
        'status' => MenuStatusEnum::class,
        'name' => SafeContent::class,
        'description' => SafeContent::class,
        'ingredients' => SafeContent::class,
        'is_featured' => 'boolean',
        'is_vegetarian' => 'boolean',
        'is_vegan' => 'boolean',
        'is_gluten_free' => 'boolean',
        'price' => 'float',
        'sale_price' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    protected function finalPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->sale_price ?: $this->price
        );
    }

    protected function hasDiscount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->sale_price && $this->sale_price < $this->price
        );
    }

    protected function discountPercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->has_discount) {
                    return 0;
                }

                return round((($this->price - $this->sale_price) / $this->price) * 100);
            }
        );
    }

    public function slugable(): MorphOne
    {
        return $this->morphOne(Slug::class, 'reference');
    }

    protected function priceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => format_price($this->price)
        );
    }

    protected function salePriceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->sale_price ? format_price($this->sale_price) : null
        );
    }

    protected function finalPriceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => format_price($this->final_price)
        );
    }
}
