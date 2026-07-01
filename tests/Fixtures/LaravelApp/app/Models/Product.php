<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public static int $categoryRelationCalls = 0;

    public static int $featuresRelationCalls = 0;

    public function category(): BelongsTo
    {
        self::$categoryRelationCalls++;

        return $this->belongsTo(Category::class);
    }

    public function features(): HasMany
    {
        self::$featuresRelationCalls++;

        return $this->hasMany(Feature::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return "{$this->name} ({$this->id})";
    }
}
