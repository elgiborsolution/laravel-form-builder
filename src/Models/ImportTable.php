<?php

namespace ESolution\DataSources\Models;

use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportTable extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    protected $fillable = [
        'import_config_id',
        'parent_id',
        'table_name',
        'data_params',
        'foreign_key',
        'primary_key',
        'key_update_delete',
        'child_update_key',
        'missing_child_strategy',
        'use_soft_delete',
    ];

    protected $casts = [
        'data_params' => 'array',
        'use_soft_delete' => 'boolean',
    ];

    public function importConfig()
    {
        return $this->belongsTo(ImportConfig::class);
    }
}
