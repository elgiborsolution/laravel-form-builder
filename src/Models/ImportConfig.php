<?php

namespace ESolution\DataSources\Models;

use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportConfig extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    protected $fillable = [
        'code',
        'name',
        'description',
        'endpoint',
        'database_scope',
        'import_mode',
        'enabled',
        'middlewares',
        'before_execute_hook',
        'after_execute_hook',
        'template_disk',
        'template_path',
        'template_original_name',
        'template_type',
        'template_metadata',
    ];

    protected $casts = [
        'middlewares' => 'array',
        'template_metadata' => 'array',
        'enabled' => 'boolean',
    ];

    public function parentTable()
    {
        return $this->hasOne(ImportTable::class)->where('parent_id', '=', 0);
    }

    public function childTables()
    {
        return $this->hasMany(ImportTable::class)->where('parent_id', '!=', 0);
    }
}
