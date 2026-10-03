<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Personero extends Model
{
    protected $fillable = [
        'dni', 'nombre', 'sexo', 'celular', 'correo', 
        'co_departamento', 'desc_departamento', 
        'co_provincia', 'desc_provincia', 
        'co_distrito', 'desc_distrito', 
        'direccion', 'cod_org_politica', 'desc_org_politica', 
        'desc_tipo_personero', 'desc_centro_vota', 'nro_mesa',
        'nro_credencial', 'fecha_acreditacion', 'estado_personero', 'estado_credencial', 'estado_descarga', 'observacion',
        'cod_local', 'cod_colegio', 'cod_ubigeo_cole', 'dir_colegio',
        'estado_check_whatsapp', 'creator', 'status'
    ];

    protected $casts = [
        'fecha_acreditacion' => 'date',
    ];

    public function local()
    {
        return $this->belongsTo(Local::class, 'cod_local', 'cod_local');
    }

    public function getColegioAttribute()
    {
        return Colegio::where('cod_colegio', trim($this->cod_colegio))
            ->where('cod_ubigeo_cole', trim($this->cod_ubigeo_cole))
            ->first();
    }
}
