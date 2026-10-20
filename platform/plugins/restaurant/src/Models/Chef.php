<?php

namespace Botble\Restaurant\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Botble\Base\Supports\Avatar;
use Botble\Media\Facades\RvMedia;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Chef extends BaseModel
{
    protected $table = 'restaurant_chefs';

    protected $fillable = [
        'name',
        'title',
        'bio',
        'image',
        'badge',
        'experience_years',
        'specialties',
        'social_links',
        'order',
        'status',
    ];

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'name' => SafeContent::class,
        'title' => SafeContent::class,
        'bio' => SafeContent::class,
        'specialties' => 'array',
        'social_links' => 'array',
    ];

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            return $this->image ? RvMedia::getImageUrl($this->image, 'thumb') : (new Avatar())->create($this->name)->toBase64();
        });
    }
}
