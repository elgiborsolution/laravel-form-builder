<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiConfig extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['route_name', 'endpoint', 'method', 'params', 'enabled', 'description'];

    /**
     * Cast attributes to a specific data type.
     * The 'params' field is cast to an array.
     *
     * @var array
     */
    protected $casts = [
        'params' => 'array',
    ];

    /**
     * Define a relationship with the ApiPermission model.
     * Each API config belongs to a specific API permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function permission()
    {
        return $this->belongsTo(ApiPermission::class);
    }

    /**
     * Define a relationship with the ApiHook model.
     * Each API config is linked to a specific API hook.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hook()
    {
        return $this->belongsTo(ApiHook::class);
    }

    /**
     * Define a relationship with the ApiTable model for parent tables.
     * Retrieves tables where 'parent_id' is 0 (indicating they are top-level tables).
     *
     * @return \Illuminate\Database\Eloquent\Relations\hasOne
     */
    public function parentTable()
    {
        return $this->hasOne(ApiTable::class)->where('parent_id', '=', 0);
    }

    /**
     * Define a relationship with the ApiTable model for child tables.
     * Retrieves tables where 'parent_id' is not 0 (indicating they are child tables).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function childTables()
    {
        return $this->hasMany(ApiTable::class)->where('parent_id', '!=', 0);
    }
}
