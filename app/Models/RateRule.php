<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
