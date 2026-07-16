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
