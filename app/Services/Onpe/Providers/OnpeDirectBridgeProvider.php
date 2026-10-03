<?php

namespace App\Services\Onpe\Providers;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;
use Throwable;

class OnpeDirectBridgeProvider implements OnpeProviderInterface
{
    protected string $scriptPath;
    protected float $timeoutSeconds;

    public function __construct()
    {
        $this->scriptPath = base_path('app/Services/Onpe/Scripts/onpe_live_bridge.js');
        $this->timeoutSeconds = 25.0;
    }

    public function getName(): string
    {
        return 'onpe_live_bridge';
    }

    public function isAvailable(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        return file_exists($this->scriptPath);
    }

    public function lookup(string $dni): ?OnpeConsultaDTO
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);
        if (strlen($cleanDni) !== 8) {
            return null;
        }

        try {
            $script = realpath($this->scriptPath) ?: $this->scriptPath;
            $cmd = 'node "' . $script . '" ' . escapeshellarg($cleanDni);
            
            $stdout = shell_exec($cmd);

            \Illuminate\Support\Facades\Log::info("[OnpeDirectBridgeProvider] DNI: {$cleanDni} | Output: " . substr($stdout ?? 'NULL', 0, 300));

            if (empty($stdout)) {
                return null;
            }

            $response = json_decode(trim($stdout), true);

            if (!is_array($response) || empty($response['success']) || empty($response['data'])) {
                return null;
            }

            $data = $response['data'];

            $nombres = trim((string)($data['nombres'] ?? ''));
            $apellidos = trim((string)($data['apellidos'] ?? ''));
            $nombreCompleto = trim("{$nombres} {$apellidos}");

            if (empty($nombreCompleto) && !empty($data['nombre_completo'])) {
                $nombreCompleto = trim((string)$data['nombre_completo']);
            }

            $ubigeo = $data['ubigeo'] ?? null;
            $region = 'LIMA';
            $provincia = 'LIMA';
            $distrito = null;

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

            $txtCenter = $data['localVotacion'] ?? ($data['txtCenter'] ?? ($data['nombreLocal'] ?? null));
            $direccionLocal = $data['direccion'] ?? ($data['direccion_local'] ?? ($data['dirLocal'] ?? null));
            $txtReferencia = $data['referencia'] ?? ($data['txtReferencia'] ?? ($data['refLocal'] ?? null));
            $nroMesa = $data['mesaSufragio'] ?? ($data['nro_mesa'] ?? ($data['mesa'] ?? null));

            $hasElectoral = !empty($distrito) || !empty($txtCenter);
            $category = ($hasElectoral && !empty($nroMesa)) ? 'COMPLETE' : 'PARTIAL';

            return OnpeConsultaDTO::success(
                dni: $cleanDni,
                nombre: $nombreCompleto,
                region: $region,
                provincia: $provincia,
                distrito: $distrito,
                contenedorLocal: $ubigeo,
                txtCenter: $txtCenter,
                direccionLocal: $direccionLocal,
                txtReferencia: $txtReferencia,
                nroMesa: $nroMesa ? (string)$nroMesa : null,
                source: $this->getName(),
                statusCategory: $category,
                rawPayload: $data
            );
        } catch (Throwable $e) {
            return null;
        }
    }
}
