<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'record_date' => 'date:Y-m-d',
    ];

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function anomalies()
    {
        return $this->hasMany(ImportAnomaly::class);
    }
}
