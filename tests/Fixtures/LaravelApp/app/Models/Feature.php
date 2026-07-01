<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feature extends Model
{
    public static int $productRelationCalls = 0;

    public function product(): BelongsTo
    {
        self::$productRelationCalls++;

        return $this->belongsTo(Product::class);
    }
}
