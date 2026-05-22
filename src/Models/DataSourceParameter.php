<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;

class DataSourceParameter extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['data_source_id', 'param_name', 'param_type', 'param_default_value', 'is_required', 'operator'];

    /**
     * Define a relationship with the DataSource model.
     * Each DataSourceParameter belongs to a specific DataSource.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function dataSource()
    {
        return $this->belongsTo(DataSource::class);
    }
}
