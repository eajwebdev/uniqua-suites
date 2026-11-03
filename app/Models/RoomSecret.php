<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomSecret extends Model
{
    protected $guarded = [];

    protected $casts = [
        'network_name' => 'encrypted',
        'password' => 'encrypted',
        'troubleshooting' => 'encrypted',
        'contact_person' => 'encrypted',
        'last_verified_on' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
