<?php

namespace App\Services\Onpe\Providers;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;
use App\Services\Onpe\Support\WafTokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NativeHttpOnpeProvider implements OnpeProviderInterface
{
    protected string $baseUrl;
    protected float $timeout;
    protected float $connectTimeout;
    protected int $retryTimes;
    protected int $retrySleepMs;
    protected WafTokenManager $wafManager;

    public function __construct(?WafTokenManager $wafManager = null)
    {
        $this->baseUrl = rtrim((string) config('services.onpe.base_url', 'https://consultaelectoral.onpe.gob.pe'), '/');
        $this->timeout = (float) config('services.onpe.timeout', 8.0);
        $this->connectTimeout = (float) config('services.onpe.connect_timeout', 4.0);
        $this->retryTimes = (int) config('services.onpe.retry_times', 2);
        $this->retrySleepMs = (int) config('services.onpe.retry_sleep_ms', 150);
        $this->wafManager = $wafManager ?? new WafTokenManager();
    }

    public function getName(): string
    {
        return 'onpe_native_http';
    }

    public function isAvailable(): bool
    {
        return (bool) config('services.onpe.enabled', true);
    }

    public function lookup(string $dni): ?OnpeConsultaDTO
    {
        $wafToken = $this->wafManager->getToken();

        $headers = [
            'User-Agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept'             => 'application/json, text/plain, */*',
            'Accept-Language'    => 'es-PE,es-419;q=0.9,es;q=0.8,en;q=0.7',
            'Content-Type'       => 'application/json',
            'Origin'             => $this->baseUrl,
            'Referer'            => "{$this->baseUrl}/inicio",
            'Sec-Ch-Ua'          => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-Ch-Ua-Mobile'   => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest'     => 'empty',
            'Sec-Fetch-Mode'     => 'cors',
            'Sec-Fetch-Site'     => 'same-origin',
        ];

        if (!empty($wafToken)) {
            $headers['Cookie'] = "aws-waf-token={$wafToken}";
        }

        try {
            // Paso 1: Búsqueda / Login por DNI
            $response1 = Http::withoutVerifying()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withHeaders($headers)
                ->post("{$this->baseUrl}/v1/api/busqueda/dni", [
                    'numeroDocumento' => $dni,
                ]);

            // Si el WAF rechazó la petición (401, 403, 405, 429, 202 o 503), invalidar token y reintentar
            if (in_array($response1->status(), [202, 401, 403, 405, 429, 502, 503])) {
                $this->wafManager->invalidateToken();
                $freshWafToken = $this->wafManager->getToken(true);
                if (!empty($freshWafToken)) {
                    $headers['Cookie'] = "aws-waf-token={$freshWafToken}";
                    $response1 = Http::withoutVerifying()
                        ->connectTimeout($this->connectTimeout)
                        ->timeout($this->timeout)
                        ->withHeaders($headers)
                        ->post("{$this->baseUrl}/v1/api/busqueda/dni", [
                            'numeroDocumento' => $dni,
                        ]);
                }
            }

            if (!$response1->successful()) {
                return null;
            }

            $json1 = $response1->json();
            $token = $json1['data']['token'] ?? null;

            if (empty($token)) {
                return null;
            }

            // Paso 2: Consulta Definitiva con Bearer Token
            $headers2 = array_merge($headers, [
                'Authorization' => "Bearer {$token}",
                'Referer'       => "{$this->baseUrl}/main/local-de-votacion",
            ]);

            $response2 = Http::withoutVerifying()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withHeaders($headers2)
                ->post("{$this->baseUrl}/v1/api/consulta/definitiva", (object)[]);

            if (!$response2->successful()) {
                return null;
            }

            $json2 = $response2->json();
            $dataDef = $json2['data'] ?? [];

            if (empty($dataDef)) {
                return null;
            }

            $nombres = trim((string)($dataDef['nombres'] ?? ''));
            $apellidos = trim((string)($dataDef['apellidos'] ?? ''));
            $nombreCompleto = trim("{$nombres} {$apellidos}");

            $ubigeo = $dataDef['ubigeo'] ?? null;
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

            $txtCenter = $dataDef['localVotacion'] ?? ($dataDef['txtCenter'] ?? ($dataDef['nombreLocal'] ?? null));
            $direccionLocal = $dataDef['direccion'] ?? ($dataDef['direccion_local'] ?? ($dataDef['dirLocal'] ?? null));
            $txtReferencia = $dataDef['referencia'] ?? ($dataDef['txtReferencia'] ?? ($dataDef['refLocal'] ?? null));
            $nroMesa = $dataDef['mesaSufragio'] ?? ($dataDef['nro_mesa'] ?? ($dataDef['mesa'] ?? null));

            if (!empty($nombreCompleto) || !empty($txtCenter) || !empty($ubigeo)) {
                $hasElectoral = !empty($distrito) || !empty($txtCenter);
                $category = ($hasElectoral && !empty($nroMesa)) ? 'COMPLETE' : 'PARTIAL';

                return OnpeConsultaDTO::success(
                    dni: $dni,
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
                    rawPayload: $dataDef
                );
            }
        } catch (Throwable $e) {
            // Silencioso para permitir fallback transparente al pipeline
        }

        return null;
    }
}
