<?php
namespace ESolution\DataSources\Models;
use ESolution\DataSources\Support\Concerns\UsesPackageDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
class ImportStagingRecord extends Model { use UsesPackageDatabaseConnection; protected $fillable = ['import_uuid','import_config_id','master_name','table_name','row_no','parent_row_key','payload','mapped_payload','status','errors','execution_order']; protected $casts = ['payload'=>'array','mapped_payload'=>'array','errors'=>'array']; }
