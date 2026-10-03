<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provincia extends Model
{
    protected $table = 'provincias';
    protected $primaryKey = 'cod_ubigeo';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cod_ubigeo',
        'cod_ubigeo_depa',
        'name',
        'stat_vista_prov',
        'creator',
        'status',
    ];

    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'cod_ubigeo_depa', 'cod_ubigeo');
    }

    public function distritos()
    {
        return $this->hasMany(Distrito::class, 'cod_ubigeo_prov', 'cod_ubigeo');
    }
}
