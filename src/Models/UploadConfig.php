<?php

namespace ESolution\DataSources\Models;

use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadConfig extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    protected $fillable = [
        'code',
        'name',
        'description',
        'endpoint',
        'upload_path',
        'max_file_size',
        'allowed_extensions',
        'multiple',
        'middlewares',
        'enabled',
    ];

    protected $casts = [
        'allowed_extensions' => 'array',
        'middlewares' => 'array',
        'multiple' => 'boolean',
        'enabled' => 'boolean',
        'max_file_size' => 'integer',
    ];
}
