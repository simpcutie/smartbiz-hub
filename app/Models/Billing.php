<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    protected $guarded = ['id'];

    public function order()
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
