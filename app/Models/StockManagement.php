<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockManagement extends Model
{
    protected $guarded = ['id'];

    protected $table = 'stock_management';

    protected function casts(): array
    {
        return ['last_stock_in' => 'datetime', 'last_stock_out' => 'datetime'];
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
