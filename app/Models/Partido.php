<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partido extends Model
{
    protected $table = 'partidos';
    protected $primaryKey = 'cod_partido';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['cod_partido', 'des_partido', 'creator', 'status'];
}
