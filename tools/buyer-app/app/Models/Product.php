<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['sku', 'name', 'category_id', 'price', 'stock', 'launch_date', 'is_active', 'locked'];

    protected $casts = [
        'price' => 'decimal:2',
        'launch_date' => 'date',
        'is_active' => 'boolean',
        'locked' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
