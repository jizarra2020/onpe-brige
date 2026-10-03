<?php

namespace App\Console\Commands;

use App\Services\Onpe\OnpeConsultaService;
use Illuminate\Console\Command;

class ConsultarOnpeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'onpe:consultar {dni : DNI de 8 dígitos del ciudadano} {--json : Imprimir exclusivamente la salida JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consulta directa al padrón electoral oficial de la ONPE en vivo';

    /**
     * Execute the console command.
     */
    public function handle(OnpeConsultaService $onpeService): int
    {
        $dni = (string) $this->argument('dni');
        $cleanDni = preg_replace('/[^0-9]/', '', $dni);

        if (strlen($cleanDni) !== 8) {
            $this->error("El DNI '{$dni}' es inválido. Debe tener exactamente 8 dígitos numéricos.");
            return 1;
        }

        if (!$this->option('json')) {
            $this->info("Consultando datos en vivo a la ONPE para DNI: {$cleanDni}...");
        }

        $startTime = microtime(true);
        $result = $onpeService->consultar($cleanDni);
        $durationMs = round((microtime(true) - $startTime) * 1000);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return !empty($result['success']) ? 0 : 1;
        }

        if (!empty($result['success'])) {
            $this->newLine();
            $this->info("✔ Datos recuperados exitosamente de la ONPE en {$durationMs}ms:");
            $this->newLine();

            $rows = [
                ['DNI', $result['dni'] ?? ''],
                ['Nombres y Apellidos', $result['nombre'] ?? ''],
                ['Región', $result['region'] ?? ''],
                ['Provincia', $result['provincia'] ?? ''],
                ['Distrito', $result['distrito'] ?? ''],
                ['Ubigeo Completo', $result['contenedor_local'] ?? ''],
                ['Local de Votación', $result['txtCenter'] ?? ''],
                ['Dirección del Local', $result['direccion_local'] ?? ''],
                ['Referencia', $result['txtReferencia'] ?? ''],
                ['Mesa de Sufragio', $result['nro_mesa'] ?? ''],
                ['N° de Orden', $result['orden'] ?? 'N/A'],
                ['Pabellón', $result['pabellon'] ?? 'N/A'],
                ['Piso', $result['piso'] ?? 'N/A'],
                ['Aula', $result['aula'] ?? 'N/A'],
                ['Cargo / Miembro de Mesa', $result['cargo'] ?? 'NO ERES MIEMBRO DE MESA'],
                ['Origen de Datos', $result['source'] ?? 'onpe_live_bridge'],
                ['Estado', $result['status_category'] ?? 'COMPLETE'],
            ];

            $this->table(['Campo', 'Valor'], $rows);

            $this->newLine();
            $this->line('<fg=gray>Estructura JSON entregada a la vista /formulario-general-onpe:</>');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();

            return 0;
        }

        $this->error("✖ No se pudieron obtener los datos de la ONPE.");
        $this->line("Mensaje: " . ($result['message'] ?? 'Error desconocido'));
        return 1;
    }
}
