<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Local extends Model
{
    protected $table = 'locales';
    protected $primaryKey = 'cod_local';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cod_local',
        'des_local_partido',
        'creator',
        'status',
    ];
}
