<?php

namespace App\Services\Onpe\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WafTokenManager
{
    const CACHE_KEY = 'onpe:waf_token';
    const CACHE_SYNCED_KEY = 'onpe:waf_token_synced_at';
    const CACHE_EXPIRES_KEY = 'onpe:waf_token_expires_at';
    const CACHE_TTL_MINUTES = 30;

    protected string $scriptPath;

    public function __construct()
    {
        $this->scriptPath = base_path('app/Services/Onpe/Scripts/onpe_waf_generator.js');
    }

    /**
     * Obtiene el token de sesión AWS WAF actual si está vigente.
     */
    public function getToken(bool $forceRefresh = false): ?string
    {
        if (app()->environment('testing')) {
            return 'mock-test-waf-token';
        }

        if (!$forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (!empty($cached)) {
                return (string) $cached;
            }

            // Fallback a almacenamiento en archivo seguro de storage
            $meta = $this->readTokenFromFile();
            if (!empty($meta['token']) && !empty($meta['is_valid'])) {
                // Calentar la caché para acelerar siguientes peticiones
                $ttlMinutes = max(5, (int) round((strtotime($meta['expires_at']) - time()) / 60));
                Cache::put(self::CACHE_KEY, $meta['token'], now()->addMinutes($ttlMinutes));
                if (!empty($meta['synced_at'])) {
                    Cache::put(self::CACHE_SYNCED_KEY, $meta['synced_at'], now()->addMinutes($ttlMinutes));
                }
                if (!empty($meta['expires_at'])) {
                    Cache::put(self::CACHE_EXPIRES_KEY, $meta['expires_at'], now()->addMinutes($ttlMinutes));
                }
                return (string) $meta['token'];
            }
        }

        return $this->generateFreshToken();
    }

    /**
     * Guarda el token en caché y en archivo persistente con metadatos completos y seguros.
     */
    public static function persistToken(string $token, int $ttlMinutes = 30): void
    {
        $ttlMinutes = max(5, min($ttlMinutes, 120));
        $syncedAt = now()->toIso8601String();
        $expiresAt = now()->addMinutes($ttlMinutes)->toIso8601String();

        Cache::put(self::CACHE_KEY, $token, now()->addMinutes($ttlMinutes));
        Cache::put(self::CACHE_SYNCED_KEY, $syncedAt, now()->addMinutes($ttlMinutes));
        Cache::put(self::CACHE_EXPIRES_KEY, $expiresAt, now()->addMinutes($ttlMinutes));

        $data = [
            'token'              => $token,
            'synced_at'          => $syncedAt,
            'expires_at'         => $expiresAt,
            'expires_in_minutes' => $ttlMinutes,
            'created_at'         => $syncedAt,
        ];

        $targetFiles = [
            storage_path('app/onpe_waf_token.json'),
            storage_path('onpe_waf_token.json'),
            storage_path('waf_token.json'),
            base_path('storage/onpe_waf_token.json'),
            base_path('storage/waf_token.json'),
        ];

        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        foreach ($targetFiles as $file) {
            try {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($file, $content);
            } catch (Throwable $e) {
                // Silencioso ante restricciones de permisos
            }
        }
    }

    /**
     * Obtiene los metadatos completos y reales del token WAF para diagnóstico.
     */
    public function getTokenMetadata(): array
    {
        $token = Cache::get(self::CACHE_KEY);
        $syncedAt = Cache::get(self::CACHE_SYNCED_KEY);
        $expiresAt = Cache::get(self::CACHE_EXPIRES_KEY);

        if (empty($token)) {
            $fileMeta = $this->readTokenFromFile();
            if (!empty($fileMeta['token'])) {
                $token = $fileMeta['token'];
                $syncedAt = $fileMeta['synced_at'] ?? null;
                $expiresAt = $fileMeta['expires_at'] ?? null;
            }
        }

        $hasToken = !empty($token);
        $isNotExpired = false;

        if ($hasToken && !empty($expiresAt)) {
            $isNotExpired = strtotime($expiresAt) > time();
        } elseif ($hasToken && empty($expiresAt)) {
            // Si hay token pero no fecha de expiración, no podemos garantizar vigencia
            $isNotExpired = false;
        }

        return [
            'has_token'          => $hasToken,
            'waf_session_active' => $hasToken && $isNotExpired,
            'token_preview'      => $hasToken ? substr($token, 0, 15) . '...' . substr($token, -10) : null,
            'synced_at'          => $syncedAt,
            'expires_at'         => $expiresAt,
            'is_expired'         => $hasToken ? !$isNotExpired : null,
        ];
    }

    /**
     * Lee metadatos del token desde archivos en storage.
     */
    protected function readTokenFromFile(): array
    {
        $filePaths = [
            storage_path('app/onpe_waf_token.json'),
            storage_path('onpe_waf_token.json'),
            storage_path('waf_token.json'),
            base_path('storage/onpe_waf_token.json'),
            base_path('storage/waf_token.json'),
        ];

        foreach ($filePaths as $filePath) {
            if (file_exists($filePath)) {
                $content = @file_get_contents($filePath);
                $json = @json_decode($content, true);

                if (is_array($json) && !empty($json['token'])) {
                    $token = (string) $json['token'];
                    $syncedAt = $json['synced_at'] ?? null;
                    $expiresAt = $json['expires_at'] ?? null;

                    if (empty($expiresAt) && !empty($json['expires'])) {
                        $expTime = $json['expires'] > 10000000000 ? ($json['expires'] / 1000) : $json['expires'];
                        $expiresAt = date('c', (int) $expTime);
                    }

                    $isValid = !empty($expiresAt) && (strtotime($expiresAt) > time());

                    return [
                        'token'      => $token,
                        'synced_at'  => $syncedAt,
                        'expires_at' => $expiresAt,
                        'is_valid'   => $isValid,
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Invalida el token en caché y archivo.
     */
    public function invalidateToken(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_SYNCED_KEY);
        Cache::forget(self::CACHE_EXPIRES_KEY);

        $targetFiles = [
            storage_path('app/onpe_waf_token.json'),
            storage_path('onpe_waf_token.json'),
            storage_path('waf_token.json'),
            base_path('storage/onpe_waf_token.json'),
            base_path('storage/waf_token.json'),
        ];

        foreach ($targetFiles as $filePath) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * Genera un token WAF localmente solo si el entorno y binarios lo soportan.
     */
    protected function generateFreshToken(): ?string
    {
        if (app()->environment('testing')) {
            return 'mock-test-waf-token';
        }

        if (!file_exists($this->scriptPath) || !function_exists('shell_exec')) {
            return null;
        }

        try {
            $script = realpath($this->scriptPath) ?: $this->scriptPath;
            $cmd = 'node "' . $script . '"';

            $stdout = @shell_exec($cmd);

            if (empty($stdout)) {
                return null;
            }

            $res = @json_decode(trim($stdout), true);

            if (is_array($res) && !empty($res['success']) && !empty($res['token'])) {
                $token = (string) $res['token'];
                self::persistToken($token, self::CACHE_TTL_MINUTES);
                Log::info("[WafTokenManager] Nuevo AWS WAF Token generado y persistido.");
                return $token;
            }
        } catch (Throwable $e) {
            Log::warning("[WafTokenManager] Error generando WAF token local: " . $e->getMessage());
        }

        return null;
    }
}

