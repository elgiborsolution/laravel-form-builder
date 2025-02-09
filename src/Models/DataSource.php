<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'table_name', 'use_custom_query', 'columns', 'custom_query'];

    protected $casts = [
        'columns' => 'array',
    ];

    public function parameters()
    {
        return $this->hasMany(DataSourceParameter::class);
    }

    public static function validateQuery($query)
    {
        $query = trim(strtolower($query));
        return str_starts_with($query, 'select ');
    }
}
