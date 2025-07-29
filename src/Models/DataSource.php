<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['name', 'table_name', 'use_custom_query', 'columns', 'custom_query'];

    /**
     * Cast attributes to specific data types.
     * The 'columns' field is stored as an array in the database.
     *
     * @var array
     */
    protected $casts = [
        'columns' => 'array',
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
     * @return bool Returns true if the query starts with 'SELECT ', otherwise false.
     */
    public static function validateQuery($query)
    {
        $query = trim(strtolower($query)); // Convert to lowercase and trim spaces
        return str_starts_with($query, 'select '); // Check if query starts with 'select '
    }
}
