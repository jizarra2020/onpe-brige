<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    protected $table = 'departamentos';
    protected $primaryKey = 'cod_ubigeo';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cod_ubigeo',
        'name',
        'stat_vista_depa',
        'creator',
        'status',
    ];

    public function provincias()
    {
        return $this->hasMany(Provincia::class, 'cod_ubigeo_depa', 'cod_ubigeo');
    }
}
