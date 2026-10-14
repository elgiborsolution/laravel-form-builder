<?php
namespace ESolution\DataSources\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;

class ApiTable extends Model
{
    use HasFactory;
    use UsesPackageDatabaseConnection;

    /**
     * The attributes that are mass assignable.
     * These fields can be filled using mass assignment.
     *
     * @var array
     */
    protected $fillable = ['api_config_id', 'parent_id', 'table_name', 'data_params', 'foreign_key', 'primary_key', 'key_update_delete', 'child_update_key', 'missing_child_strategy', 'use_soft_delete'];
    
    /**
     * Cast attributes to a specific data type.
     * The 'data_params' field is cast as an array.
     *
     * @var array
     */
    protected $casts = [
        'data_params' => 'array',
        'use_soft_delete' => 'boolean',
    ];

    /**
     * Keep the parent operation metadata in the existing data_params column
     * without exposing it as a database mapping.
     */
    public function getDataParamsAttribute(mixed $value): array
    {
        $dataParams = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($dataParams)) {
            return [];
        }

        unset($dataParams['__parent_operation']);

        return $dataParams;
    }

    public function getOperationAttribute(): string
    {
        return $this->parentOperationMetadata()['operation'];
    }

    public function getLookupKeysAttribute(): array
    {
        return $this->parentOperationMetadata()['lookup_keys'];
    }

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        if ((int) $this->parent_id === 0 && $this->operation === 'CREATE_OR_UPDATE') {
            $attributes['operation'] = $this->operation;
            $attributes['lookup_keys'] = $this->lookup_keys;
        }

        return $attributes;
    }

    protected function parentOperationMetadata(): array
    {
        $dataParams = $this->getAttributes()['data_params'] ?? null;
        if (is_string($dataParams)) {
            $dataParams = json_decode($dataParams, true);
        }

        $metadata = is_array($dataParams) ? ($dataParams['__parent_operation'] ?? null) : null;
        if (! is_array($metadata) || ($metadata['version'] ?? null) !== 1) {
            return ['operation' => 'CREATE', 'lookup_keys' => []];
        }

        return [
            'operation' => ($metadata['operation'] ?? null) === 'CREATE_OR_UPDATE' ? 'CREATE_OR_UPDATE' : 'CREATE',
            'lookup_keys' => is_array($metadata['lookup_keys'] ?? null) ? array_values($metadata['lookup_keys']) : [],
        ];
    }

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
