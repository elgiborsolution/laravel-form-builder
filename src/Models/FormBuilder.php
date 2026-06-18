<?php
namespace ESolution\DataSources\Models;

use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormBuilder extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['code', 'name', 'description', 'enabled', 'schema'];

    /**
     * Cast attributes to specific data types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'enabled' => 'boolean',
        'schema' => 'array',
    ];
}

