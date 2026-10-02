<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function stock()
    {
        return $this->hasOne(StockManagement::class);
    }

    public function getAvailableAttribute(): int
    {
        return max(0, ($this->stock?->on_hand ?? 0) - ($this->stock?->reserved ?? 0));
    }
}
