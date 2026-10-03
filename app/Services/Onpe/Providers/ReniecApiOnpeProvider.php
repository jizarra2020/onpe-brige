<?php

namespace App\Services\Onpe\Providers;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReniecApiOnpeProvider implements OnpeProviderInterface
{
    public function getName(): string
    {
        return 'reniec_identity';
    }

    public function isAvailable(): bool
    {
        $enabled = (bool) config('services.onpe.enabled', true);
        if (!$enabled) {
            return false;
        }

        if (app()->environment('testing')) {
            return (bool) config('services.onpe.enable_reniec_fallback', false);
        }

        return true;
    }

    public function lookup(string $dni): ?OnpeConsultaDTO
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);
        if (strlen($cleanDni) !== 8) {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(4.0)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept'     => 'application/json',
                ])
                ->get("https://api.apis.net.pe/v2/reniec/dni?numero={$cleanDni}");

            if (!$response->successful()) {
                $response = Http::withoutVerifying()
                    ->timeout(4.0)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                        'Accept'     => 'application/json',
                    ])
                    ->get("https://api.apis.net.pe/v1/dni?numero={$cleanDni}");
            }

            if ($response->successful()) {
                $json = $response->json();
                if (is_array($json)) {
                    $nombre = $json['nombreCompleto'] ?? ($json['nombre_completo'] ?? ($json['nombre'] ?? null));
                    if (empty($nombre)) {
                        $nombres = trim((string)($json['nombres'] ?? ''));
                        $paterno = trim((string)($json['apellidoPaterno'] ?? ($json['apellido_paterno'] ?? '')));
                        $materno = trim((string)($json['apellidoMaterno'] ?? ($json['apellido_materno'] ?? '')));
                        if (!empty($nombres)) {
                            $nombre = trim("{$nombres} {$paterno} {$materno}");
                        }
                    }

                    if (!empty($nombre)) {
                        return OnpeConsultaDTO::success(
                            dni: $cleanDni,
                            nombre: $nombre,
                            region: $json['departamento'] ?? null,
                            provincia: $json['provincia'] ?? null,
                            distrito: $json['distrito'] ?? null,
                            contenedorLocal: null,
                            txtCenter: null,
                            direccionLocal: null,
                            txtReferencia: null,
                            nroMesa: null,
                            source: $this->getName(),
                            statusCategory: 'IDENTITY_ONLY',
                            rawPayload: $json
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            // Silencioso para permitir fallback transparente
        }

        return null;
    }
}
