<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    public const SUITES = 'suites';

    public const CAMERAS = 'cameras';

    protected $guarded = [];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
