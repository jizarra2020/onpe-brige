<?php

namespace App\Services\Onpe;

use App\Services\Onpe\Contracts\OnpeProviderInterface;
use App\Services\Onpe\DTO\OnpeConsultaDTO;
use App\Services\Onpe\Providers\CustomApiOnpeProvider;
use App\Services\Onpe\Providers\MockOnpeProvider;
use App\Services\Onpe\Providers\NativeHttpOnpeProvider;
use App\Services\Onpe\Providers\OnpeDirectBridgeProvider;
use App\Services\Onpe\Providers\ReniecApiOnpeProvider;
use App\Support\OnpeResponseMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnpeConsultaService
{
    /**
     * @var OnpeProviderInterface[]
     */
    protected array $providers = [];

    public function __construct()
    {
        $this->registerDefaultProviders();
    }

    /**
     * Registra los proveedores de consulta configurando el pipeline de failover inteligente.
     * Capa 1: Vercel Bridge / API Externa (CustomApiOnpeProvider) - Resuelve WAF en la nube y extrae datos electorales.
     * Capa 2: NativeHttpOnpeProvider - Consulta HTTP directa con token WAF si existe sesión válida.
     * Capa 3: OnpeDirectBridgeProvider - Ejecución Node local (en desarrollo).
     * Capa 4: ReniecApiOnpeProvider - Recuperación de identidad de respaldo garantizada (APIs RENIEC).
     * Capa 5: MockOnpeProvider (solo en pruebas/entorno mock).
     */
    protected function registerDefaultProviders(): void
    {
        $customApi = new CustomApiOnpeProvider();
        $nativeHttp = new NativeHttpOnpeProvider();
        $directBridge = new OnpeDirectBridgeProvider();
        $reniecApi = new ReniecApiOnpeProvider();
        $mock = new MockOnpeProvider();

        $driver = config('services.onpe.driver', 'auto');

        if ($driver === 'mock') {
            $this->providers = [$mock];
            return;
        }

        if ($driver === 'native') {
            $this->providers = [$nativeHttp, $customApi, $reniecApi, $mock];
            return;
        }

        // Modo 'auto' o 'api': Prioriza CustomApiOnpeProvider (Vercel Bridge) con failover a HTTP directo y RENIEC
        $this->providers = [
            $customApi,
            $nativeHttp,
            $directBridge,
            $reniecApi,
            $mock,
        ];
    }

    /**
     * Permite registrar o anteponer un proveedor personalizado.
     */
    public function addProvider(OnpeProviderInterface $provider, bool $prepend = false): self
    {
        if ($prepend) {
            array_unshift($this->providers, $provider);
        } else {
            $this->providers[] = $provider;
        }

        return $this;
    }

    /**
     * Ejecuta la consulta de DNI a través del pipeline de proveedores.
     * Incorpora caché seguro de 24 horas para respuestas exitosas.
     *
     * @param string $dni
     * @return array
     */
    public function consultar(string $dni): array
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);

        // 1. Validación estricta de formato (8 dígitos numéricos peruanos)
        if (strlen($cleanDni) !== 8) {
            return OnpeResponseMapper::toFallbackArray(
                dni: $cleanDni,
                message: 'El DNI debe tener exactamente 8 dígitos numéricos.',
                source: 'validation_error',
                statusCategory: 'INVALID_FORMAT'
            );
        }

        // 2. Verificar switch general de activación
        $isEnabled = (bool) config('services.onpe.enabled', true);
        if (!$isEnabled) {
            return OnpeResponseMapper::toFallbackArray(
                dni: $cleanDni,
                message: 'Consulta automática deshabilitada. Puede ingresar sus datos manualmente.',
                source: 'disabled',
                statusCategory: 'FALLBACK_MANUAL'
            );
        }

        // 3. Caché de alta velocidad para DNIs consultados previamente (0ms)
        if (!app()->environment('testing')) {
            $cacheKey = "onpe:dni:result:{$cleanDni}";
            $cachedResult = Cache::get($cacheKey);
            if (is_array($cachedResult) && !empty($cachedResult['success'])) {
                return $cachedResult;
            }
        }

        $startTime = microtime(true);
        $identityCandidate = null;

        // 4. Consultar en vivo a los proveedores en orden de prioridad
        try {
            foreach ($this->providers as $provider) {
                if (!$provider->isAvailable()) {
                    continue;
                }

                $providerStart = microtime(true);
                $dto = $provider->lookup($cleanDni);

                if ($dto !== null && $dto->success && !empty($dto->nombre)) {
                    $durationMs = round((microtime(true) - $providerStart) * 1000);
                    $this->logTechnicalDiagnostic($cleanDni, $provider->getName(), 200, $dto->hasElectoralData, $durationMs);

                    // Si tiene datos electorales completos de la ONPE, retornar inmediatamente
                    if ($dto->hasElectoralData) {
                        $mappedResult = OnpeResponseMapper::toArray($dto);

                        if (!app()->environment('testing')) {
                            Cache::put("onpe:dni:result:{$cleanDni}", $mappedResult, now()->addHours(24));
                        }

                        return $mappedResult;
                    }

                    // Guardar como candidato de identidad y continuar buscando datos electorales en otros proveedores
                    if ($identityCandidate === null) {
                        $identityCandidate = $dto;
                    }
                }
            }

            // Si ningún proveedor obtuvo datos electorales completos pero tenemos identidad
            if ($identityCandidate !== null) {
                $mappedResult = OnpeResponseMapper::toArray($identityCandidate);

                if (!app()->environment('testing')) {
                    Cache::put("onpe:dni:result:{$cleanDni}", $mappedResult, now()->addHours(24));
                }

                return $mappedResult;
            }
        } catch (ConnectionException $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);
            $this->logTechnicalDiagnostic($cleanDni, 'TIMEOUT_CONNECTION', 504, false, $durationMs, ['error' => $e->getMessage()]);
            return OnpeResponseMapper::toFallbackArray(
                dni: $cleanDni,
                message: 'Tiempo de espera agotado al consultar la página de la ONPE. Puedes ingresar tus datos manualmente.',
                source: 'upstream_timeout',
                statusCategory: 'TIMEOUT'
            );
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);
            $this->logTechnicalDiagnostic($cleanDni, 'EXCEPTION', 500, false, $durationMs, ['error' => $e->getMessage()]);
        }

        // 5. Fallback final seguro si la fuente oficial no responde
        $totalDuration = round((microtime(true) - $startTime) * 1000);
        $this->logTechnicalDiagnostic($cleanDni, 'FALLBACK_MANUAL', 404, false, $totalDuration);

        return OnpeResponseMapper::toFallbackArray(
            dni: $cleanDni,
            message: 'No pudimos obtener los datos automáticamente de la ONPE. Puedes ingresarlos manualmente.',
            source: 'fallback_manual',
            statusCategory: 'FALLBACK_MANUAL'
        );
    }

    /**
     * Logging técnico seguro enmascarando parcialmente el DNI (ej. 2188****).
     */
    protected function logTechnicalDiagnostic(string $dni, string $provider, int $status, bool $hasElectoralData, float $durationMs, array $extra = []): void
    {
        $clean = preg_replace('/[^0-9]/', '', $dni);
        $maskedDni = strlen($clean) === 8 ? substr($clean, 0, 4) . '****' : 'INVALID';
        $electoralTag = $hasElectoralData ? 'YES' : 'NO';

        Log::info("[ONPE Service Diagnostic] DNI: {$maskedDni} | Provider: {$provider} | Status: {$status} | HasElectoralData: {$electoralTag} | Duration: {$durationMs}ms", $extra);
    }
}

