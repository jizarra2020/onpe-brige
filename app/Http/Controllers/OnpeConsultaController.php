<?php

namespace App\Http\Controllers;

use App\Services\Onpe\OnpeConsultaService;
use App\Services\Onpe\Support\WafTokenManager;
use App\Support\OnpeResponseMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class OnpeConsultaController extends Controller
{
    /**
     * Endpoint API para consultar datos de identidad y padrón electoral por DNI.
     *
     * @param string $dni
     * @param OnpeConsultaService $onpeService
     * @return JsonResponse
     */
    public function consultar(string $dni, OnpeConsultaService $onpeService): JsonResponse
    {
        try {
            $cleanDni = preg_replace('/[^0-9]/', '', (string) $dni);

            $validator = Validator::make(
                ['dni' => $cleanDni],
                ['dni' => ['required', 'digits:8', 'numeric']]
            );

            if ($validator->fails()) {
                return response()->json(
                    OnpeResponseMapper::toFallbackArray(
                        dni: $cleanDni,
                        message: 'El DNI debe tener exactamente 8 dígitos numéricos.',
                        source: 'validation_error',
                        statusCategory: 'INVALID_FORMAT'
                    ),
                    200
                );
            }

            $result = $onpeService->consultar($cleanDni);

            return response()->json($result, 200);
        } catch (Throwable $e) {
            return response()->json(
                OnpeResponseMapper::toFallbackArray(
                    dni: $dni,
                    message: 'Error temporal en el servicio de consulta. Puede ingresar sus datos manualmente.',
                    source: 'controller_exception',
                    statusCategory: 'UPSTREAM_ERROR'
                ),
                200
            );
        }
    }

    /**
     * Endpoint API para validar y normalizar datos brutos obtenidos en vivo desde la ONPE.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function normalizar(Request $request): JsonResponse
    {
        try {
            $rawDni = (string) $request->input('dni', '');
            $cleanDni = preg_replace('/[^0-9]/', '', $rawDni);

            $validator = Validator::make(
                ['dni' => $cleanDni],
                ['dni' => ['required', 'digits:8', 'numeric']]
            );

            if ($validator->fails()) {
                return response()->json(
                    OnpeResponseMapper::toFallbackArray(
                        dni: $cleanDni,
                        message: 'El DNI debe tener exactamente 8 dígitos numéricos.',
                        source: 'validation_error',
                        statusCategory: 'INVALID_FORMAT'
                    ),
                    200
                );
            }

            $rawOnpeData = $request->input('raw_onpe_data', $request->all());
            if (!is_array($rawOnpeData) || empty($rawOnpeData)) {
                return response()->json(
                    OnpeResponseMapper::toFallbackArray(
                        dni: $cleanDni,
                        message: 'No se recibieron datos válidos para normalizar.',
                        source: 'empty_payload',
                        statusCategory: 'INVALID_RESPONSE'
                    ),
                    200
                );
            }

            $normalized = OnpeResponseMapper::fromRawOnpeData($cleanDni, $rawOnpeData, 'onpe_live_bridge');

            return response()->json($normalized, 200);
        } catch (Throwable $e) {
            return response()->json(
                OnpeResponseMapper::toFallbackArray(
                    dni: (string) $request->input('dni', ''),
                    message: 'Error al normalizar los datos recibidos. Puede ingresarlos manualmente.',
                    source: 'normalization_exception',
                    statusCategory: 'UPSTREAM_ERROR'
                ),
                200
            );
        }
    }

    /**
     * Endpoint para sincronizar de forma segura el AWS WAF Token generado externamente.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function syncWafToken(Request $request): JsonResponse
    {
        try {
            $providedKey = (string) ($request->input('sync_key') ?? $request->header('X-ONPE-SYNC-KEY', $request->query('sync_key', '')));
            $expectedKey = (string) (config('services.onpe.sync_key') ?: env('ONPE_SYNC_KEY') ?: 'onpe_bridge_secret_key_2026');

            if (empty($providedKey) || !hash_equals($expectedKey, $providedKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autorizado: Llave de sincronización inválida.',
                ], 401);
            }

            $token = (string) $request->input('waf_token', '');
            if (empty($token) || strlen($token) < 20) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token WAF inválido o incompleto.',
                ], 422);
            }

            $ttlMinutes = (int) $request->input('ttl_minutes', 30);
            $ttlMinutes = max(5, min($ttlMinutes, 120));

            WafTokenManager::persistToken($token, $ttlMinutes);

            return response()->json([
                'success'            => true,
                'message'            => 'Token AWS WAF sincronizado exitosamente.',
                'synced_at'          => now()->toIso8601String(),
                'expires_at'         => now()->addMinutes($ttlMinutes)->toIso8601String(),
                'expires_in_minutes' => $ttlMinutes,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Endpoint diagnóstico para verificar el estado de la integración ONPE y de la sesión WAF en cPanel.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statusWafToken(Request $request): JsonResponse
    {
        $wafManager = new WafTokenManager();
        $meta = $wafManager->getTokenMetadata();
        $driver = (string) config('services.onpe.driver', 'auto');
        $isEnabled = (bool) config('services.onpe.enabled', true);

        return response()->json([
            'status'             => 'ok',
            'onpe_enabled'       => $isEnabled,
            'driver'             => $driver,
            'primary_provider'   => 'vercel_bridge',
            'token_present'      => $meta['has_token'],
            'waf_session_active' => $meta['waf_session_active'],
            'token_preview'      => $meta['token_preview'],
            'synced_at'          => $meta['synced_at'],
            'expires_at'         => $meta['expires_at'],
            'integration_status' => $isEnabled ? 'operational' : 'disabled',
            'server_time'        => now()->toIso8601String(),
        ], 200);
    }
}


