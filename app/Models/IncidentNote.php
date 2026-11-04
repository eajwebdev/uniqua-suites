<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only: the app offers no way to edit or remove a logged communication. */
class IncidentNote extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
