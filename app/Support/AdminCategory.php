<?php

namespace App\Support;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin's Suites / Cameras switch, remembered in the session. Every admin page shows one
 * business at a time. Records with no property or room of their own (a general expense, a
 * payment not linked to a booking) belong to Suites, the business that existed before Grab Cam.
 */
class AdminCategory
{
    public const OPTIONS = [Property::SUITES, Property::CAMERAS];

    /** Pages that only make sense for one business. The sidebar hides them; switching away from one lands on the dashboard. */
    public const SUITES_ONLY = ['/admin/quotes', '/admin/housekeeping', '/admin/laundry', '/admin/parking', '/admin/inventory', '/admin/advances', '/admin/imports'];

    public const CAMERAS_ONLY = ['/admin/camera-rates'];

    public static function current(): string
    {
        $value = session('admin_category', Property::SUITES);

        return in_array($value, self::OPTIONS, true) ? $value : Property::SUITES;
    }

    public static function isCameras(): bool
    {
        return self::current() === Property::CAMERAS;
    }

    /** @return list<int> */
    public static function propertyIds(): array
    {
        return Property::where('category', self::current())->pluck('id')->all();
    }

    /** Whether a page belongs to the business that is not selected. */
    public static function hides(string $path): bool
    {
        $list = self::isCameras() ? self::SUITES_ONLY : self::CAMERAS_ONLY;
        foreach ($list as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix.'?')) {
                return true;
            }
        }

        return false;
    }

    /** Narrows a rooms query to the chosen business. */
    public static function rooms(Builder $rooms): Builder
    {
        return self::isCameras() ? $rooms->cameras() : $rooms->suites();
    }

    /** Narrows a bookings query by its property. */
    public static function bookings(Builder $bookings): Builder
    {
        return $bookings->whereIn('property_id', self::propertyIds());
    }

    /**
     * Narrows any query on a column holding a property id. A null column (nothing linked) counts as Suites.
     * Works on Eloquent and plain query builders, and on join clauses.
     */
    public static function whereProperty($query, $column)
    {
        $ids = self::propertyIds();
        $unlinked = ! self::isCameras();

        return $query->where(fn ($w) => $w->whereIn($column, $ids)->when($unlinked, fn ($o) => $o->orWhereNull($column)));
    }

    /** Expenses belong where their room is, else their property; with neither they are a general (Suites) cost. */
    public static function expenses(Builder $query): Builder
    {
        $ids = self::propertyIds();

        return $query->where(fn ($w) => $w->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->orWhere(fn ($o) => $o->whereNull('room_id')->whereIn('property_id', $ids))
            ->when(! self::isCameras(), fn ($o) => $o->orWhere(fn ($g) => $g->whereNull('room_id')->whereNull('property_id'))));
    }

    /** Work orders belong where their room is, else their own property; with neither they are Suites. */
    public static function workOrders(Builder $query): Builder
    {
        $ids = self::propertyIds();

        return $query->where(fn ($w) => $w->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->orWhere(fn ($o) => $o->whereNull('room_id')->whereIn('property_id', $ids))
            ->when(! self::isCameras(), fn ($o) => $o->orWhere(fn ($g) => $g->whereNull('room_id')->whereNull('property_id'))));
    }

    /** As whereProperty, for a model whose business comes through a relation (booking, room). */
    public static function whereHasProperty(Builder $query, string $relation): Builder
    {
        $ids = self::propertyIds();

        return $query->where(fn ($w) => $w->whereHas($relation, fn ($r) => $r->whereIn('property_id', $ids))
            ->when(! self::isCameras(), fn ($o) => $o->orWhereDoesntHave($relation)));
    }
}
