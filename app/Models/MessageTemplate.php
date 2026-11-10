<?php

namespace App\Models;

use App\Domain\Bookings\MessageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTemplate extends Model
{
    use SoftDeletes;

    /** Filled in by the Quotation screen, which needs it, so it can be edited but not deleted. */
    public const QUOTE = 'quote';

    protected $guarded = [];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /** Grab Cam templates are told apart by their key; every other template is for the suites. */
    public function isCamera(): bool
    {
        return str_starts_with($this->key, MessageService::CAMERA_PREFIX);
    }

    public function isSystem(): bool
    {
        return $this->key === self::QUOTE;
    }
}
