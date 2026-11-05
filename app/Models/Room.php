<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $guarded = [];

    protected $casts = [
        'has_balcony' => 'boolean',
        'is_published' => 'boolean',
        'parking_eligible' => 'boolean',
        'active_from' => 'date:Y-m-d',
        'active_to' => 'date:Y-m-d',
        'catalog_approved_at' => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function type()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function images()
    {
        return $this->hasMany(RoomImage::class)->orderBy('position');
    }

    public function amenities()
    {
        return $this->belongsToMany(Amenity::class);
    }

    public function rateRules()
    {
        return $this->hasMany(RateRule::class);
    }

    public function blocks()
    {
        return $this->hasMany(RoomBlock::class);
    }

    public function secret()
    {
        return $this->hasOne(RoomSecret::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true)->where('operational_status', '!=', 'retired');
    }

    public function hasOpenSafetyBlock(): bool
    {
        return WorkOrder::where('room_id', $this->id)
            ->where('safety_block', true)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }
}
