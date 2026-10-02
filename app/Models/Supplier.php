<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function purchases()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
