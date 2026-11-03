<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSource extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
        'is_legacy_only' => 'boolean',
    ];
}
