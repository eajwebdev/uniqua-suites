<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalCalendarFeed extends Model
{
    protected $guarded = [];

    protected $casts = [
        'url' => 'encrypted',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
