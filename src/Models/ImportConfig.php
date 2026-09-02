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
        'before_stage_hook',
        'after_stage_hook',
        'before_final_hook',
        'after_final_hook',
        'template_disk',
        'template_path',
        'template_original_name',
        'template_type',
        'template_metadata',
        'custom_parameters',
    ];

    protected $casts = [
        'middlewares' => 'array',
        'template_metadata' => 'array',
        'enabled' => 'boolean',
        'custom_parameters' => 'array',
    ];

    public function parentTable()
    {
        return $this->hasOne(ImportTable::class)->where('parent_id', '=', 0);
    }

    /**
     * All independent root tables for this import.  parentTable() remains for
     * consumers of the legacy single-parent contract.
     */
    public function masterParents()
    {
        return $this->hasMany(ImportTable::class)
            ->where('parent_id', '=', 0)
            ->orderBy('id');
    }

    public function importTables()
    {
        return $this->hasMany(ImportTable::class);
    }

    public function childTables()
    {
        return $this->hasMany(ImportTable::class)->where('parent_id', '!=', 0);
    }
}
