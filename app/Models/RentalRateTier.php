<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A camera model's daily rate for rentals of min_days to max_days (null: and above). */
class RentalRateTier extends Model
{
    protected $guarded = [];

    protected $casts = [
        'min_days' => 'integer',
        'max_days' => 'integer',
        'daily_cents' => 'integer',
        'free_day_from' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function type()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function label(): string
    {
        return $this->max_days === null ? "{$this->min_days}+ day rate" : "{$this->min_days}–{$this->max_days} day rate";
    }
}
