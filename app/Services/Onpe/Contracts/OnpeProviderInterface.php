<?php

namespace App\Services\Onpe\Contracts;

use App\Services\Onpe\DTO\OnpeConsultaDTO;

interface OnpeProviderInterface
{
    /**
     * Identificador único del proveedor.
     */
    public function getName(): string;

    /**
     * Determina si el proveedor está habilitado según la configuración actual.
     */
    public function isAvailable(): bool;

    /**
     * Ejecuta la consulta para un DNI dado.
     * Retorna OnpeConsultaDTO o null si este proveedor no pudo resolver los datos.
     */
    public function lookup(string $dni): ?OnpeConsultaDTO;
}
