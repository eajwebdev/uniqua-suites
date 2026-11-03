<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentEvidence extends Model
{
    protected $guarded = [];

    protected $table = 'incident_evidence';

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }
}
