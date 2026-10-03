<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Distrito extends Model
{
    protected $table = 'distritos';
    protected $primaryKey = 'cod_ubigeo';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cod_ubigeo',
        'cod_ubigeo_prov',
        'name',
        'stat_vista_dist',
        'creator',
        'status',
    ];

    public function provincia()
    {
        return $this->belongsTo(Provincia::class, 'cod_ubigeo_prov', 'cod_ubigeo');
    }
}
