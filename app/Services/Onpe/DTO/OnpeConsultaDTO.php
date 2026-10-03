<?php

namespace App\Services\Onpe\DTO;

class OnpeConsultaDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $dni,
        public readonly ?string $nombre = null,
        public readonly ?string $region = null,
        public readonly ?string $provincia = null,
        public readonly ?string $distrito = null,
        public readonly ?string $contenedorLocal = null,
        public readonly ?string $txtCenter = null,
        public readonly ?string $direccionLocal = null,
        public readonly ?string $txtReferencia = null,
        public readonly ?string $nroMesa = null,
        public readonly bool $hasElectoralData = false,
        public readonly bool $manualEntry = false,
        public readonly ?string $source = null,
        public readonly ?string $message = null,
        public readonly string $statusCategory = 'COMPLETE',
        public readonly array $rawPayload = []
    ) {}

    /**
     * Instancia un DTO exitoso con datos electorales completos o parciales.
     */
    public static function success(
        string $dni,
        string $nombre,
        ?string $region = 'LIMA',
        ?string $provincia = 'LIMA',
        ?string $distrito = null,
        ?string $contenedorLocal = null,
        ?string $txtCenter = null,
        ?string $direccionLocal = null,
        ?string $txtReferencia = null,
        ?string $nroMesa = null,
        ?string $source = 'onpe_native',
        ?string $message = null,
        ?string $statusCategory = null,
        array $rawPayload = []
    ): self {
        $hasElectoral = !empty($distrito) || !empty($txtCenter) || !empty($contenedorLocal);
        $category = $statusCategory ?? (($hasElectoral && !empty($nroMesa)) ? 'COMPLETE' : 'PARTIAL');

        return new self(
            success: true,
            dni: $dni,
            nombre: $nombre,
            region: $region ?? 'LIMA',
            provincia: $provincia ?? 'LIMA',
            distrito: $distrito,
            contenedorLocal: $contenedorLocal,
            txtCenter: $txtCenter,
            direccionLocal: $direccionLocal,
            txtReferencia: $txtReferencia,
            nroMesa: $nroMesa,
            hasElectoralData: $hasElectoral,
            manualEntry: false,
            source: $source,
            message: $message,
            statusCategory: $category,
            rawPayload: $rawPayload
        );
    }

    /**
     * Instancia un DTO de identidad parcial (ej. identidad recuperada sin centro electoral).
     */
    public static function partialIdentity(
        string $dni,
        string $nombre,
        ?string $region = 'LIMA',
        ?string $provincia = 'LIMA',
        ?string $distrito = null,
        ?string $source = 'reniec_fallback',
        ?string $message = 'Datos de identidad recuperados. Por favor seleccione su distrito y centro de votación.',
        array $rawPayload = []
    ): self {
        return new self(
            success: true,
            dni: $dni,
            nombre: $nombre,
            region: $region ?? 'LIMA',
            provincia: $provincia ?? 'LIMA',
            distrito: $distrito,
            contenedorLocal: null,
            txtCenter: null,
            direccionLocal: null,
            txtReferencia: null,
            nroMesa: null,
            hasElectoralData: false,
            manualEntry: false,
            source: $source,
            message: $message,
            statusCategory: 'PARTIAL',
            rawPayload: $rawPayload
        );
    }

    /**
     * Instancia un DTO de fallback / modo manual seguro sin lanzar error 500.
     */
    public static function fallback(
        string $dni,
        string $message = 'No pudimos obtener los datos automáticamente. Puedes ingresarlos manualmente.',
        ?string $source = 'fallback_manual',
        string $statusCategory = 'FALLBACK_MANUAL'
    ): self {
        return new self(
            success: false,
            dni: $dni,
            nombre: null,
            region: null,
            provincia: null,
            distrito: null,
            contenedorLocal: null,
            txtCenter: null,
            direccionLocal: null,
            txtReferencia: null,
            nroMesa: null,
            hasElectoralData: false,
            manualEntry: true,
            source: $source,
            message: $message,
            statusCategory: $statusCategory,
            rawPayload: []
        );
    }
}
