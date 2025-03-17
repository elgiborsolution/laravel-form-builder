<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataTableBuilder extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'columns', 'params', 'filters', 'actions'];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
        'params' => 'array',
        'actions' => 'array',
    ];

}
