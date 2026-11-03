<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSnapshot extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
