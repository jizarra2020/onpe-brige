<?php

namespace App\Services\Onpe\Providers;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;

class MockOnpeProvider implements OnpeProviderInterface
{
    public function getName(): string
    {
        return 'mock';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function lookup(string $dni): ?OnpeConsultaDTO
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);

        $driver = config('services.onpe.driver');
        if ($driver === 'mock') {
            if ($cleanDni === '00000000' || $cleanDni === '99999999') {
                return null;
            }

            return OnpeConsultaDTO::success(
                dni: $cleanDni,
                nombre: 'CIUDADANO EJEMPLAR',
                region: 'LIMA',
                provincia: 'LIMA',
                distrito: 'LIMA',
                contenedorLocal: 'LIMA / LIMA / LIMA',
                txtCenter: 'LOCAL DE VOTACION EJEMPLO',
                direccionLocal: 'DIRECCION EJEMPLO 123',
                txtReferencia: 'Referencia: CERCA A PARQUE PRINCIPAL',
                nroMesa: '012345',
                source: 'mock',
                statusCategory: 'COMPLETE'
            );
        }

        return null;
    }
}
