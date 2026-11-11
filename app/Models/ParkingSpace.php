<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingSpace extends Model
{
    /** Condo-owned: the stall is ours to rent out, and its fees are our sales. */
    public const OWN = 'own';

    /** Third-party: lent by another owner; what guests pay for it is remitted to them. */
    public const BORROWED = 'borrowed';

    public const ACTIVE = 'active';

    /** Temporarily out of use (repairs, the owner wants it back for a while). Not offered for new assignments. */
    public const UNAVAILABLE = 'unavailable';

    /** No longer used. Hidden from the calendar and the assignment form. */
    public const INACTIVE = 'inactive';

    public const OWNERSHIP_LABELS = [self::OWN => 'Condo-owned', self::BORROWED => 'Third-party'];

    protected $guarded = [];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function isBorrowed(): bool
    {
        return $this->ownership === self::BORROWED;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function ownershipLabel(): string
    {
        return self::OWNERSHIP_LABELS[$this->ownership] ?? 'Condo-owned';
    }

    /** "P-12 · Tower A" style label: the slot number, plus its name when it has one. */
    public function label(): string
    {
        return $this->name ? "{$this->code} · {$this->name}" : $this->code;
    }

    public function assignments()
    {
        return $this->hasMany(ParkingAssignment::class);
    }

    public function blocks()
    {
        return $this->hasMany(ParkingBlock::class);
    }
}
