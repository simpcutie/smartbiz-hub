<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = ['id'];

    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
