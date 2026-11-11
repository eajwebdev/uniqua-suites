<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A stall kept out of use for some dates: the owner is using it, repairs, or it is otherwise unavailable. */
class ParkingBlock extends Model
{
    public const REASONS = ['owner_use' => 'Owner using it', 'maintenance' => 'Maintenance', 'unavailable' => 'Temporarily unavailable', 'other' => 'Other'];

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function space()
    {
        return $this->belongsTo(ParkingSpace::class, 'parking_space_id');
    }
}
