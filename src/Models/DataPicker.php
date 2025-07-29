<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataPicker extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['name', 'code', 'columns', 'params', 'filters'];

    /**
     * Cast attributes to specific data types.
     * The 'columns', 'filters', and 'params' fields are stored as arrays in the database.
     *
     * @var array
     */
    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
        'params' => 'array',
    ];
}
