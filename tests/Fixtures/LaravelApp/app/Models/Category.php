<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public static int $productsRelationCalls = 0;

    public function products(): HasMany
    {
        self::$productsRelationCalls++;

        return $this->hasMany(Product::class);
    }

    public function displayName(): Attribute
    {
        return Attribute::make(get: fn () => $this->name);
    }
}
