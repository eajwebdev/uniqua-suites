<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HousekeepingTask extends Model
{
    public const TYPES = [
        'cleaning' => 'Cleaning',
        'deep_clean' => 'Deep clean',
        'restock' => 'Restock',
        'inspection' => 'Room check',
        'other' => 'Other task',
    ];

    protected $guarded = [];

    protected $casts = [
        'blocks_room' => 'boolean',
        'requires_inspection' => 'boolean',
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'inspected_at' => 'datetime',
        'checklist' => 'array',
    ];

    /** Only a checkout's turnover, or a task the manager marked as blocking, changes whether a room is ready. */
    public function affectsRoom(): bool
    {
        return $this->type === 'turnover' || $this->blocks_room;
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events()
    {
        return $this->hasMany(TaskEvent::class);
    }

    public function attachments()
    {
        return $this->hasMany(TaskAttachment::class);
    }
}
