<?php

namespace App\Support;

use App\Services\Onpe\DTO\OnpeConsultaDTO;

class OnpeResponseMapper
{
    /**
     * Transforma un OnpeConsultaDTO al formato JSON nativo estricto consumido por el frontend.
     *
     * @param OnpeConsultaDTO $dto
     * @return array
     */
    public static function toArray(OnpeConsultaDTO $dto): array
    {
        if (!$dto->success) {
            return self::toFallbackArray(
                dni: $dto->dni,
                message: $dto->message ?? 'No pudimos obtener los datos automáticamente. Puedes ingresarlos manualmente.',
                source: $dto->source ?? 'fallback_manual',
                statusCategory: $dto->statusCategory ?? 'FALLBACK_MANUAL'
            );
        }

        $cleanNombre = self::cleanString($dto->nombre);
        $cleanRegion = self::cleanString($dto->region) ?? 'LIMA';
        $cleanProvincia = self::cleanString($dto->provincia) ?? 'LIMA';
        $cleanDistrito = self::cleanString($dto->distrito);
        $cleanContenedor = self::cleanString($dto->contenedorLocal);

        // Si distrito está vacío pero hay contenedor_local formato REGION / PROVINCIA / DISTRITO
        if (empty($cleanDistrito) && !empty($cleanContenedor)) {
            $parts = array_map('trim', explode('/', $cleanContenedor));
            if (count($parts) >= 3) {
                $cleanRegion = self::cleanString($parts[0]) ?? 'LIMA';
                $cleanProvincia = self::cleanString($parts[1]) ?? 'LIMA';
                $cleanDistrito = self::cleanString($parts[2]);
            } elseif (count($parts) > 0) {
                $cleanDistrito = self::cleanString(end($parts));
            }
        }

        // Si tenemos distrito pero no contenedor_local, construir contenedor canónico
        if (empty($cleanContenedor) && !empty($cleanDistrito)) {
            $cleanContenedor = "{$cleanRegion} / {$cleanProvincia} / {$cleanDistrito}";
        }

        $cleanTxtCenter = self::cleanString($dto->txtCenter);
        $cleanDireccion = self::cleanString($dto->direccionLocal);
        
        $cleanReferencia = self::cleanString($dto->txtReferencia, false);
        if (!empty($cleanReferencia) && !str_starts_with(strtoupper($cleanReferencia), 'REFERENCIA:')) {
            $cleanReferencia = 'Referencia: ' . $cleanReferencia;
        }

        $cleanMesa = self::cleanString($dto->nroMesa, false);
        $hasElectoral = !empty($cleanDistrito) || !empty($cleanTxtCenter);

        $raw = $dto->rawPayload ?? [];
        $cleanOrden = self::cleanString($raw['orden'] ?? null, false);
        $cleanPabellon = self::cleanString($raw['pabellon'] ?? null, false);
        $cleanPiso = self::cleanString($raw['piso'] ?? null, false);
        $cleanAula = self::cleanString($raw['aula'] ?? null, false);
        $cleanCargo = self::cleanString($raw['cargo'] ?? null, false);
        $isMiembroMesa = !empty($raw['miembroMesa']) || !empty($raw['miembro_mesa']) || (isset($raw['cargo']) && stripos((string)$raw['cargo'], 'NO ERES') === false);

        $response = [
            'success'            => true,
            'dni'                => $dto->dni,
            'nombre'             => $cleanNombre,
            'region'             => $cleanRegion,
            'provincia'          => $cleanProvincia,
            'distrito'           => $cleanDistrito,
            'contenedor_local'   => $cleanContenedor,
            'txtCenter'          => $cleanTxtCenter,
            'direccion_local'    => $cleanDireccion,
            'txtReferencia'      => $cleanReferencia,
            'nro_mesa'           => $cleanMesa,
            'orden'              => $cleanOrden,
            'pabellon'           => $cleanPabellon,
            'piso'               => $cleanPiso,
            'aula'               => $cleanAula,
            'cargo'              => $cleanCargo,
            'miembro_mesa'       => $isMiembroMesa,
            'has_electoral_data' => $hasElectoral,
            'manual_entry'       => false,
            'source'             => $dto->source ?? 'onpe_live_bridge',
            'status_category'    => $dto->statusCategory ?? ($hasElectoral ? 'COMPLETE' : 'PARTIAL'),
            'raw_payload'        => $raw,
        ];

        if (!empty($dto->message)) {
            $response['message'] = $dto->message;
        }

        return $response;
    }

    /**
     * Mapea un payload bruto recibido directamente desde la API oficial de la ONPE.
     *
     * @param string $dni
     * @param array $rawData
     * @param string $source
     * @return array
     */
    public static function fromRawOnpeData(string $dni, array $rawData, string $source = 'onpe_live'): array
    {
        $data = $rawData['data'] ?? $rawData;

        $nombres = trim((string)($data['nombres'] ?? ($data['nombre'] ?? '')));
        $apellidos = trim((string)($data['apellidos'] ?? ''));
        $nombreCompleto = trim("{$nombres} {$apellidos}");

        if (empty($nombreCompleto) && !empty($data['nombre_completo'])) {
            $nombreCompleto = trim((string)$data['nombre_completo']);
        }

        if (empty($nombreCompleto)) {
            return self::toFallbackArray(
                dni: $dni,
                message: 'No se encontraron datos de identidad en la respuesta de ONPE.',
                source: $source,
                statusCategory: 'NOT_FOUND'
            );
        }

        $ubigeo = $data['ubigeo'] ?? ($data['contenedor_local'] ?? null);
        $region = 'LIMA';
        $provincia = 'LIMA';
        $distrito = $data['distrito'] ?? null;

        if (!empty($ubigeo)) {
            $parts = array_map('trim', explode('/', $ubigeo));
            if (count($parts) >= 3) {
                $region = $parts[0];
                $provincia = $parts[1];
                $distrito = $parts[2];
            } elseif (count($parts) > 0) {
                $distrito = end($parts);
            }
        }

        $txtCenter = $data['localVotacion'] ?? ($data['txtCenter'] ?? ($data['nombreLocal'] ?? ($data['local_votacion'] ?? null)));
        $direccion = $data['direccion'] ?? ($data['direccion_local'] ?? ($data['dirLocal'] ?? null));
        $referencia = $data['referencia'] ?? ($data['txtReferencia'] ?? ($data['refLocal'] ?? null));
        $mesa = $data['mesaSufragio'] ?? ($data['nro_mesa'] ?? ($data['mesa'] ?? null));

        $dto = OnpeConsultaDTO::success(
            dni: $dni,
            nombre: $nombreCompleto,
            region: $region,
            provincia: $provincia,
            distrito: $distrito,
            contenedorLocal: $ubigeo,
            txtCenter: $txtCenter,
            direccionLocal: $direccion,
            txtReferencia: $referencia,
            nroMesa: $mesa ? (string)$mesa : null,
            source: $source,
            rawPayload: $data
        );

        return self::toArray($dto);
    }

    /**
     * Genera la estructura segura para modo manual / fallback.
     */
    public static function toFallbackArray(
        string $dni,
        string $message = 'No pudimos obtener los datos automáticamente. Puedes ingresarlos manualmente.',
        ?string $source = 'fallback_manual',
        string $statusCategory = 'FALLBACK_MANUAL'
    ): array {
        return [
            'success'            => false,
            'dni'                => $dni,
            'nombre'             => null,
            'region'             => null,
            'provincia'          => null,
            'distrito'           => null,
            'contenedor_local'   => null,
            'txtCenter'          => null,
            'direccion_local'    => null,
            'txtReferencia'      => null,
            'nro_mesa'           => null,
            'has_electoral_data' => false,
            'manual_entry'       => true,
            'source'             => $source ?? 'fallback_manual',
            'status_category'    => $statusCategory,
            'message'            => $message,
        ];
    }

    /**
     * Limpia y normaliza cadenas de texto respetando codificación UTF-8.
     */
    private static function cleanString(?string $value, bool $toUpper = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || in_array(strtolower($trimmed), ['null', 'undefined', 'none', '-'])) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed);
        return $toUpper ? mb_strtoupper($normalized, 'UTF-8') : $normalized;
    }
}
