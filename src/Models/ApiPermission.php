<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiPermission extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['api_config_id', 'permission_string'];

    /**
     * Define a relationship with the ApiConfig model.
     * Each API Permission belongs to a specific API Configuration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apiConfig()
    {
        return $this->belongsTo(ApiConfig::class);
    }
}
