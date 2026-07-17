<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;

class ApiHook extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['api_config_id', 'data_source_id', 'action_type', 'listener_class'];

    /**
     * Define a relationship with the ApiConfig model.
     * Each API Hook belongs to a specific API Configuration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apiConfig()
    {
        return $this->belongsTo(ApiConfig::class);
    }

    /**
     * Define a relationship with the DataSource model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function dataSource()
    {
        return $this->belongsTo(DataSource::class);
    }
}
