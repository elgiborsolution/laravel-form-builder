<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;

class ApiConfig extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['route_name', 'endpoint', 'method', 'params', 'enabled', 'description', 'middlewares'];

    /**
     * Cast attributes to a specific data type.
     * The 'params' field is cast to an array.
     *
     * @var array
     */
    protected $casts = [
        'params' => 'array',
        'middlewares' => 'array',
    ];

    protected $appends = [
        'listener_path',
        'before_execute_hook_path',
    ];

    /**
     * Define a relationship with the ApiPermission model.
     * Each API config belongs to a specific API permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function permission()
    {
        return $this->hasOne(ApiPermission::class);
    }

    /**
     * Define a relationship with the ApiHook model.
     * Each API config is linked to a specific API hook.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function hook()
    {
        return $this->hasOne(ApiHook::class)->where('action_type', 'after_hit_api');
    }

    /**
     * Define a relationship with the ApiHook model for before execute hooks.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function beforeExecuteHook()
    {
        return $this->hasOne(ApiHook::class)->where('action_type', 'before_execute');
    }

    /**
     * Define a relationship with all ApiHook rows for this API config.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hooks()
    {
        return $this->hasMany(ApiHook::class);
    }

    public function getListenerPathAttribute(): ?string
    {
        return $this->hook?->listener_class;
    }

    public function getBeforeExecuteHookPathAttribute(): ?string
    {
        return $this->beforeExecuteHook?->listener_class;
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
