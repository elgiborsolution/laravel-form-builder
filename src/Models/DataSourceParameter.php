<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataSourceParameter extends Model
{
    use HasFactory;

    protected $fillable = ['data_source_id', 'param_name', 'param_type', 'param_default_value', 'is_required'];

    public function dataSource()
    {
        return $this->belongsTo(DataSource::class);
    }
}
