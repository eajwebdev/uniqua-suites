<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomListing extends Model
{
    protected $guarded = [];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
