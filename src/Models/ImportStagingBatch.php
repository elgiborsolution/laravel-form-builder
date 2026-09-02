<?php
namespace ESolution\DataSources\Models;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
class ImportStagingBatch extends Model { use UsesPackageDatabaseConnection; protected $fillable = ['import_uuid','import_config_id','user_id','tenant_key','connection_name','status','total','success_count','failed_count','dataset']; protected $casts = ['dataset'=>'array']; public function records() { return $this->hasMany(ImportStagingRecord::class, 'import_uuid', 'import_uuid'); } }
