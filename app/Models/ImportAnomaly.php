<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportAnomaly extends Model
{
    protected $guarded = [];

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function row()
    {
        return $this->belongsTo(LegacyRow::class, 'legacy_row_id');
    }
}
