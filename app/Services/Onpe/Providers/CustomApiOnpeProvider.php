<?php

namespace App\Services\Onpe\Providers;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;
use Illuminate\Support\Facades\Http;
use Throwable;

class CustomApiOnpeProvider implements OnpeProviderInterface
{
    protected ?string $token;
    protected string $baseUrl;
    protected float $timeout;
    protected string $method;

    public function __construct()
    {
        $this->token = config('services.onpe.api_token');
        $this->baseUrl = (string) config('services.onpe.api_url', 'https://onpe-brige.vercel.app/api/onpe/{dni}');
        $this->timeout = (float) config('services.onpe.timeout', 8.0);
        $this->method = strtoupper((string) config('services.onpe.api_method', 'AUTO'));
    }

    public function getName(): string
    {
        return 'custom_token_api';
    }

    public function isAvailable(): bool
    {
        $enabled = (bool) config('services.onpe.enabled', true);
        if (!$enabled) {
            return false;
        }

        if (app()->environment('testing')) {
            $driver = config('services.onpe.driver', 'auto');
            return $driver === 'api' || !empty($this->token);
        }

        return !empty($this->baseUrl);
    }

    public function lookup(string $dni): ?OnpeConsultaDTO
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);
        if (strlen($cleanDni) !== 8) {
            return null;
        }

        try {
            $url = $this->baseUrl;
            $isTemplate = str_contains($url, '{dni}');
            if ($isTemplate) {
                $url = str_replace('{dni}', $cleanDni, $url);
            }

            $httpClient = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->acceptJson();

            if (!empty($this->token)) {
                $httpClient = $httpClient->withToken($this->token);
            }

            // Determinar método HTTP (GET vs POST)
            $isGet = ($this->method === 'GET') || $isTemplate || str_contains($this->baseUrl, 'apis.net.pe') || str_contains($this->baseUrl, '?');

            if ($isGet) {
                $queryParams = [];
                if (!$isTemplate && !str_contains($url, 'numero=') && !str_contains($url, 'dni=')) {
                    $queryParams = ['numero' => $cleanDni, 'dni' => $cleanDni];
                }
                $response = $httpClient->get($url, $queryParams);
            } else {
                $response = $httpClient->post($url, [
                    'dni'             => $cleanDni,
                    'numero'          => $cleanDni,
                    'numeroDocumento' => $cleanDni,
                ]);
            }

            if (!$response->successful()) {
                return null;
            }

            $json = $response->json();
            if (!is_array($json)) {
                return null;
            }

            if (isset($json['success']) && $json['success'] === false) {
                return null;
            }

            $data = $json['data'] ?? $json;
            if (!is_array($data)) {
                return null;
            }

            // 1. Extracción de Nombre Completo
            $nombre = $data['nombre_completo'] ?? ($data['nombreCompleto'] ?? ($data['nombre'] ?? null));

            if (empty($nombre)) {
                $nombres = trim((string)($data['nombres'] ?? ($data['nombre'] ?? '')));
                $apellidos = trim((string)($data['apellidos'] ?? ''));
                if (!empty($nombres) && !empty($apellidos)) {
                    $nombre = trim("{$nombres} {$apellidos}");
                } elseif (!empty($nombres)) {
                    $paterno = trim((string)($data['apellido_paterno'] ?? ($data['apellidoPaterno'] ?? ($data['paterno'] ?? ''))));
                    $materno = trim((string)($data['apellido_materno'] ?? ($data['apellidoMaterno'] ?? ($data['materno'] ?? ''))));
                    $nombre = trim("{$nombres} {$paterno} {$materno}");
                }
            }

            if (empty($nombre)) {
                return null;
            }

            // 2. Extracción de Ubigeo y Datos Geográficos
            $distrito = $data['distrito'] ?? ($data['ubigeo_distrito'] ?? null);
            $provincia = $data['provincia'] ?? ($data['ubigeo_provincia'] ?? 'LIMA');
            $departamento = $data['departamento'] ?? ($data['region'] ?? ($data['ubigeo_departamento'] ?? 'LIMA'));
            $contenedorLocal = $data['ubigeo'] ?? ($data['contenedor_local'] ?? null);

            if (is_array($contenedorLocal)) {
                $contenedorLocal = implode(' / ', array_filter($contenedorLocal));
            }

            if (empty($distrito) && !empty($contenedorLocal) && is_string($contenedorLocal)) {
                $parts = array_map('trim', explode('/', $contenedorLocal));
                if (count($parts) >= 3) {
                    $departamento = $parts[0];
                    $provincia = $parts[1];
                    $distrito = $parts[2];
                } elseif (count($parts) > 0) {
                    $distrito = end($parts);
                }
            }

            // 3. Extracción de Datos Electorales (si la API los incluye)
            $txtCenter = $data['localVotacion'] ?? ($data['local_votacion'] ?? ($data['txtCenter'] ?? ($data['nombreLocal'] ?? null)));
            $direccion = $data['direccion'] ?? ($data['direccion_local'] ?? ($data['dirLocal'] ?? null));
            $referencia = $data['referencia'] ?? ($data['txtReferencia'] ?? ($data['refLocal'] ?? null));
            $mesa = $data['mesaSufragio'] ?? ($data['mesa'] ?? ($data['nro_mesa'] ?? null));

            $hasElectoral = !empty($distrito) || !empty($txtCenter);
            $category = ($hasElectoral && !empty($mesa)) ? 'COMPLETE' : ($hasElectoral ? 'PARTIAL' : 'IDENTITY_ONLY');
            $sourceName = $json['source'] ?? $this->getName();

            return OnpeConsultaDTO::success(
                dni: $cleanDni,
                nombre: $nombre,
                region: $departamento,
                provincia: $provincia,
                distrito: $distrito,
                contenedorLocal: is_string($contenedorLocal) ? $contenedorLocal : null,
                txtCenter: $txtCenter,
                direccionLocal: $direccion,
                txtReferencia: $referencia,
                nroMesa: $mesa ? (string)$mesa : null,
                source: $sourceName,
                statusCategory: $category,
                rawPayload: $data
            );
        } catch (Throwable $e) {
            // Permitir fallback seguro sin romper la ejecución
        }

        return null;
    }
}


