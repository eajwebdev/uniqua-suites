<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $guarded = [];

    protected $casts = [
        'executed_on' => 'date:Y-m-d',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }
}
