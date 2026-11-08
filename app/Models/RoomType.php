<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function rateTiers()
    {
        return $this->hasMany(RentalRateTier::class);
    }
}
