<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;

class DataSource extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'table_name',
        'database_scope',
        'use_custom_query',
        'use_soft_delete',
        'columns',
        'custom_query',
        'middlewares',
        'response_type',
        'custom_parameters',
    ];

    /**
     * Cast attributes to specific data types.
     * The 'columns' field is stored as an array in the database.
     *
     * @var array
     */
    protected $casts = [
        'use_soft_delete' => 'boolean',
        'columns' => 'array',
        'middlewares' => 'array',
        'custom_parameters' => 'array',
    ];

    protected $appends = [
        'listener_path',
        'before_execute_hook_path',
        'after_execute_hook_path',
        'generate_before_execute_hook',
        'generate_after_execute_hook',
    ];

    /**
     * Define a one-to-many relationship with DataSourceParameter.
     * A DataSource can have multiple associated parameters.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function parameters()
    {
        return $this->hasMany(DataSourceParameter::class);
    }

    public function hooks()
    {
        return $this->hasMany(ApiHook::class);
    }

    public function beforeExecuteHook()
    {
        return $this->hasOne(ApiHook::class)->where('action_type', 'before_execute');
    }

    public function afterExecuteHook()
    {
        return $this->hasOne(ApiHook::class)->where('action_type', 'after_execute');
    }

    public function getListenerPathAttribute(): ?string
    {
        return $this->afterExecuteHook?->listener_class;
    }

    public function getBeforeExecuteHookPathAttribute(): ?string
    {
        return $this->beforeExecuteHook?->listener_class;
    }

    public function getAfterExecuteHookPathAttribute(): ?string
    {
        return $this->afterExecuteHook?->listener_class;
    }

    public function getGenerateBeforeExecuteHookAttribute(): bool
    {
        return $this->beforeExecuteHook !== null;
    }

    public function getGenerateAfterExecuteHookAttribute(): bool
    {
        return $this->afterExecuteHook !== null;
    }

    /**
     * Validate if the given query is a SELECT statement.
     * Ensures that only SELECT queries are used to prevent modifications to the database.
     *
     * @param string $query The SQL query to be validated.
     * @return bool Returns true if the query starts with SELECT followed by whitespace, otherwise false.
     */
    public static function validateQuery($query)
    {
        $query = trim((string) $query);

        return preg_match('/^\s*select\b/i', $query) === 1;
    }
}
