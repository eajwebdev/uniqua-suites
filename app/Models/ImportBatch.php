<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'committed_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(LegacyRow::class);
    }

    public function anomalies()
    {
        return $this->hasMany(ImportAnomaly::class);
    }
}
