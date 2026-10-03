<?php

namespace App\Services;

use App\Services\Onpe\OnpeConsultaService;

class DniLookupService
{
    protected OnpeConsultaService $onpeService;

    public function __construct(OnpeConsultaService $onpeService)
    {
        $this->onpeService = $onpeService;
    }

    /**
     * Realiza la consulta de DNI delegando a la arquitectura modular de OnpeConsultaService.
     * Mantiene retrocompatibilidad total con llamadas existentes.
     *
     * @param string $dni
     * @return array
     */
    public function lookup(string $dni): array
    {
        return $this->onpeService->consultar($dni);
    }
}
