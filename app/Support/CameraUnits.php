<?php

namespace App\Support;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Str;

/** Creates a physical camera unit under Grab Cam Rental, with the suite-only facts fixed for one renter. */
class CameraUnits
{
    public static function create(RoomType $type, ?string $code = null, ?string $specs = null): Room
    {
        $property = Property::where('category', Property::CAMERAS)->orderBy('id')->firstOrFail();
        $code = trim((string) $code) ?: Str::upper(Str::slug($type->name));
        $slug = Str::slug("{$type->name} {$code}");
        $alone = ! Room::where('room_type_id', $type->id)->exists() && $code === Str::upper(Str::slug($type->name));

        return Room::create([
            'property_id' => $property->id,
            'room_type_id' => $type->id,
            'name' => $alone ? $type->name : "{$type->name} ({$code})",
            'slug' => $alone ? Str::slug($type->name) : $slug,
            'unit_code' => $code,
            'specs' => $specs,
            'bedrooms' => 0,
            'ac_count' => 0,
            'bathrooms' => 0,
            'included_guests' => 1,
            'max_guests' => 1,
            'parking_eligible' => false,
            'housekeeping_status' => 'ready',
            'catalog_status' => 'approved',
            'is_published' => true,
        ]);
    }
}
