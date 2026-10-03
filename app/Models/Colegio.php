<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Colegio extends Model
{
    protected $table = 'colegios';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cod_colegio',
        'cod_ubigeo_cole',
        'des_local_cole',
        'dir_colegio',
        'cantidad_mesas',
        'creator',
        'status',
    ];

    public function distrito()
    {
        return $this->belongsTo(Distrito::class, 'cod_ubigeo_cole', 'cod_ubigeo');
    }
}
