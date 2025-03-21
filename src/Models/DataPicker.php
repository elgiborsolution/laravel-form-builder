<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataPicker extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'columns', 'params', 'filters'];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
        'params' => 'array',
    ];
    
}
