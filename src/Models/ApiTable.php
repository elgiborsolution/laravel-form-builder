<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiTable extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['api_config_id', 'parent_id', 'table_name', 'data_params', 'foreign_key', 'primary_key'];
    
    /**
     * Cast attributes to a specific data type.
     * The 'data_params' field is cast as an array.
     *
     * @var array
     */
    protected $casts = [
        'data_params' => 'array',
    ];

    /**
     * Define a relationship with the ApiConfig model.
     * Each ApiTable belongs to a specific API Configuration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apiConfig()
    {
        return $this->belongsTo(ApiConfig::class);
    }
}
