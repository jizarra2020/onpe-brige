<?php

namespace App\Http\Controllers;

use App\Models\Personero;
use App\Services\CredencialPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DescargaCertificadoController extends Controller
{
    /**
     * Muestra la pantalla inicial (Parte 1) de consulta por DNI.
     */
    public function index()
    {
        return view('formulario.form-descarga-certificado');
    }

    /**
     * Procesa la consulta de DNI y retorna los datos del personero (Parte 2) o mensaje de error.
     */
    public function consultar(Request $request)
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$request->input('dni'));

        $request->merge(['dni' => $cleanDni]);

        $request->validate([
            'dni' => 'required|numeric|digits:8',
        ], [
            'dni.required' => 'El número de DNI es obligatorio.',
            'dni.numeric'  => 'El DNI debe contener únicamente números.',
            'dni.digits'   => 'El DNI debe tener exactamente 8 dígitos.',
        ]);

        $personero = Personero::where('dni', $cleanDni)->first();

        if (!$personero) {
            return redirect()->route('descarga-certificado.index')
                ->withInput(['dni' => $cleanDni])
                ->with('error_dni', 'No se encontró información asociada al DNI ingresado. Verifique el número e intente nuevamente.');
        }

        // Criterio restrictivo: Solo "Si" exacto autoriza la descarga
        $isAuthorized = (trim((string)$personero->estado_credencial) === 'Si');

        return view('formulario.form-descarga-certificado', [
            'personero'    => $personero,
            'isAuthorized' => $isAuthorized,
        ]);
    }

    /**
     * Genera y descarga la credencial PDF validando estrictamente en backend el estado_credencial.
     */
    public function descargar(Request $request, string $dni, CredencialPdfService $pdfService)
    {
        $cleanDni = preg_replace('/[^0-9]/', '', (string)$dni);

        if (strlen($cleanDni) !== 8) {
            return redirect()->route('descarga-certificado.index')
                ->with('error_dni', 'El formato del DNI no es válido.');
        }

        $personero = Personero::where('dni', $cleanDni)->first();

        if (!$personero) {
            return redirect()->route('descarga-certificado.index')
                ->with('error_dni', 'No se encontró información del personero solicitado.');
        }

        // REGLA CRÍTICA DE AUTORIZACIÓN EN BACKEND
        $estadoCredencial = trim((string)$personero->estado_credencial);
        if ($estadoCredencial !== 'Si') {
            Log::warning("Intento de descarga de credencial no autorizada para DNI: {$cleanDni} con estado_credencial: '{$estadoCredencial}'");
            
            return redirect()->route('descarga-certificado.index')
                ->with('warning_no_autorizado', 
                    'Tu credencial se encuentra actualmente en proceso de autorización. 
                    Por el momento, no estás autorizado para realizar la descarga. Si necesitas más información o asistencia, comunícate con nosotros vía WhatsApp al 951 651 528...')
                ->withInput(['dni' => $cleanDni]);
        }

        try {
            $pdfContent = $pdfService->generateSingle($personero);

            // Actualizar estado_descarga a 'Si' tras la generación exitosa
            $personero->update(['estado_descarga' => 'Si']);

            $filename = "credencial-personero-{$cleanDni}.pdf";

            return response($pdfContent, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                'Pragma'              => 'no-cache',
                'Expires'             => '0',
            ]);
        } catch (\Throwable $e) {
            Log::error("Error al generar PDF de credencial para DNI {$cleanDni}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('descarga-certificado.index')
                ->with('error_dni', 'Ocurrió un inconveniente al generar su credencial en PDF. Por favor, intente nuevamente o comuníquese con soporte.')
                ->withInput(['dni' => $cleanDni]);
        }
    }
}
