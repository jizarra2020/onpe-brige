<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\Provincia;
use App\Models\Distrito;
use App\Models\Partido;
use App\Models\Personero;
use App\Models\Local;
use App\Models\Colegio;
use App\Services\CredencialPdfService;
use Illuminate\Http\Request;

class PersoneroController extends Controller
{
    public function index()
    {
        $departamentos = Departamento::orderBy('name')->get();
        $partidos = Partido::orderBy('des_partido')->get();
        $locales = Local::where('status', 1)->orderBy('des_local_partido')->get();
        return view('personeros.index', compact('departamentos', 'partidos', 'locales'));
    }

    public function dashboard()
    {
        $total = Personero::count();
        $porTipo = Personero::selectRaw('desc_tipo_personero, count(*) as total')
            ->groupBy('desc_tipo_personero')
            ->get();

        $departamentosDistritos = Personero::selectRaw('
                COALESCE(NULLIF(TRIM(desc_departamento), ""), "SIN ESPECIFICAR") as departamento,
                COALESCE(NULLIF(TRIM(desc_distrito), ""), "SIN DISTRITO") as distrito,
                COUNT(*) as total
            ')
            ->groupBy('departamento', 'distrito')
            ->orderBy('departamento')
            ->orderByDesc('total')
            ->get()
            ->groupBy('departamento');

        $porDepartamento = $departamentosDistritos->map(function ($distritos, $depa) {
            return (object) [
                'desc_departamento' => $depa,
                'total' => $distritos->sum('total'),
                'distritos' => $distritos->map(fn($d) => (object) [
                    'nombre' => $d->distrito,
                    'total' => $d->total
                ])->values()
            ];
        })->sortByDesc('total')->take(5)->values();

        $recientes = Personero::orderBy('created_at', 'desc')->limit(5)->get();

        return view('personeros.dashboard', compact('total', 'porTipo', 'porDepartamento', 'recientes'));
    }

    private function getFilteredQuery(Request $request)
    {
        $query = Personero::with('local');

        if ($request->filled('dni')) {
            $query->where('dni', 'like', '%' . $request->dni . '%');
        }
        if ($request->filled('nombre')) {
            $query->where('nombre', 'like', '%' . $request->nombre . '%');
        }
        if ($request->filled('celular')) {
            $query->where('celular', 'like', '%' . $request->celular . '%');
        }
        if ($request->filled('departamento')) {
            $query->where('desc_departamento', 'like', '%' . $request->departamento . '%');
        }
        if ($request->filled('provincia')) {
            $query->where('desc_provincia', 'like', '%' . $request->provincia . '%');
        }
        if ($request->filled('distrito')) {
            $query->where('desc_distrito', 'like', '%' . $request->distrito . '%');
        }
        if ($request->filled('tipo')) {
            $query->where('desc_tipo_personero', 'like', '%' . $request->tipo . '%');
        }
        if ($request->filled('mesa')) {
            $query->where('nro_mesa', 'like', '%' . $request->mesa . '%');
        }
        if ($request->filled('centro')) {
            $query->where('desc_centro_vota', 'like', '%' . $request->centro . '%');
        }
        if ($request->filled('estado_personero')) {
            $query->where('estado_personero', $request->estado_personero);
        }
        if ($request->filled('nro_credencial')) {
            $query->where('nro_credencial', 'like', '%' . $request->nro_credencial . '%');
        }
        if ($request->filled('cod_local')) {
            $query->where('cod_local', $request->cod_local);
        }
        if ($request->filled('colegio')) {
            $query->whereExists(function ($q) use ($request) {
                $q->select(\DB::raw(1))
                  ->from('colegios')
                  ->whereColumn('colegios.cod_colegio', 'personeros.cod_colegio')
                  ->whereColumn('colegios.cod_ubigeo_cole', 'personeros.cod_ubigeo_cole')
                  ->where('colegios.des_local_cole', 'like', '%' . $request->colegio . '%');
            });
        }

        return $query;
    }

    public function list(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $personeros = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString();
        $locales = Local::where('status', 1)->orderBy('des_local_partido')->get();

        return view('personeros.list', compact('personeros', 'locales'));
    }

    public function export(Request $request)
    {
        $query = $this->getFilteredQuery($request);

        $filename = 'listado_personeros_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function() use ($query) {
            $file = fopen('php://output', 'w');
            
            // Add UTF-8 BOM so Excel opens it with accents correctly
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Set headers for columns (semicolon separator for Spanish locales)
            fputcsv($file, [
                'ITM',
                'Fecha Registro',
                'DNI',
                'Nombre Completo',
                'Sexo',
                'Celular',
                'Correo',
                'Departamento',
                'Provincia',
                'Distrito',
                'Dirección',
                'Organización Política',
                'Tipo Personero',
                'Local del Partido',
                'Colegio Asignado',
                'Centro Votación',
                'Nro Mesa',
                'Nro Credencial',
                'Fecha Acreditación',
                'Estado Personero',
                'Estado Credencial',
                'Observación'
            ], ';');

            $itemNumber = 1;
            $query->orderBy('created_at', 'desc')->chunk(500, function($personeros) use ($file, &$itemNumber) {
                foreach ($personeros as $p) {
                    fputcsv($file, [
                        $itemNumber++,
                        $p->created_at ? $p->created_at->format('Y-m-d H:i:s') : '',
                        '="' . str_pad($p->dni, 8, '0', STR_PAD_LEFT) . '"',
                        $p->nombre,
                        $p->sexo,
                        $p->celular,
                        $p->correo,
                        $p->desc_departamento,
                        $p->desc_provincia,
                        $p->desc_distrito,
                        $p->direccion,
                        $p->desc_org_politica,
                        $p->desc_tipo_personero,
                        $p->local?->des_local_partido ?? '---',
                        $p->colegio?->des_local_cole ?? '---',
                        $p->desc_centro_vota,
                        $p->nro_mesa,
                        $p->nro_credencial,
                        $p->fecha_acreditacion ? $p->fecha_acreditacion->format('Y-m-d') : '',
                        $p->estado_personero ?: 'Pendiente',
                        $p->estado_credencial ?: 'No',
                        $p->observacion
                    ], ';');
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function edit(Personero $personero)
    {
        $departamentos = Departamento::orderBy('name')->get();
        $provincias = Provincia::where('cod_ubigeo_depa', $personero->co_departamento)->orderBy('name')->get();
        $distritos = Distrito::where('cod_ubigeo_prov', $personero->co_provincia)->orderBy('name')->get();
        $partidos = Partido::orderBy('des_partido')->get();
        $locales = Local::where('status', 1)->orderBy('des_local_partido')->get();
        
        $colegios = [];
        if ($personero->co_distrito) {
            $colegios = Colegio::where('cod_ubigeo_cole', $personero->co_distrito)->orderBy('des_local_cole')->get();
        }
        
        return view('personeros.edit', compact('personero', 'departamentos', 'provincias', 'distritos', 'partidos', 'locales', 'colegios'));
    }

    public function update(Request $request, Personero $personero)
    {
        if (!$request->filled('correo')) {
            $request->merge(['correo' => 'sincorreo@gmail.com']);
        }

        if ($request->input('correo') === 'sincorreo@gmail.com' && $request->filled('dni')) {
            $request->merge(['correo' => 'sincorreo+' . $request->input('dni') . '@gmail.com']);
        }

        if ($request->filled('co_distrito') && !$request->filled('cod_ubigeo_cole')) {
            $request->merge(['cod_ubigeo_cole' => $request->co_distrito]);
        }

        $request->validate([
            'dni' => 'required|numeric|digits:8|unique:personeros,dni,' . $personero->id,
            'nombre' => 'required|string|max:255',
            'sexo' => 'required|in:Masculino,Femenino',
            'celular' => 'required|numeric|digits:9',
            'correo' => 'required|email|max:120|unique:personeros,correo,' . $personero->id,
            'co_departamento' => 'required',
            'co_provincia' => 'required',
            'co_distrito' => 'required',
            'direccion' => 'required|max:180',
            'cod_org_politica' => 'required',
            'desc_tipo_personero' => 'required|max:50',
            'desc_centro_vota' => 'required|max:180',
            'nro_mesa' => 'nullable|numeric|max_digits:8',
            'cod_local' => 'required|exists:locales,cod_local',
            'cod_colegio' => 'required|string|max:6',
            'cod_ubigeo_cole' => 'required|string|max:6',
            'nro_credencial' => 'nullable|numeric|max_digits:8',
            'fecha_acreditacion' => 'nullable|date',
            'estado_personero' => 'nullable|in:Pendiente,Aprobado,Observado',
            'estado_credencial' => 'nullable|in:Si,No',
            'observacion' => 'nullable|max:180',
        ], [
            'dni.unique' => 'El número de DNI ya se encuentra registrado.',
            'dni.required' => 'El campo DNI es obligatorio.',
            'sexo.required' => 'Debe seleccionar el sexo.',
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
            'dni.numeric' => 'El DNI debe contener solo números.',
            'celular.required' => '¡Completa este campo!',
            'celular.numeric' => 'El celular debe contener solo números.',
            'celular.digits' => 'El NRO CELULAR debe tener 9 dígitos.',
            'nro_mesa.numeric' => 'La mesa debe contener solo números.',
            'nro_credencial.numeric' => 'La credencial debe contener solo números.',
            'correo.unique' => 'El correo electrónico ya se encuentra registrado.',
            'correo.email' => 'Ingrese un formato de correo válido.',
            'correo.required' => 'El campo correo es obligatorio.',
            'cod_colegio.required' => 'El local de votación (colegio) es obligatorio.',
            'cod_ubigeo_cole.required' => 'El código de ubigeo del colegio es obligatorio.',
        ]);

        $colegio = Colegio::where('cod_colegio', trim($request->cod_colegio))
            ->where('cod_ubigeo_cole', trim($request->cod_ubigeo_cole))
            ->first();
        if (!$colegio && $request->filled('co_distrito')) {
            $colegio = Colegio::where('cod_colegio', trim($request->cod_colegio))
                ->where('cod_ubigeo_cole', trim($request->co_distrito))
                ->first();
        }
        if (!$colegio) {
            return redirect()->back()->withErrors(['cod_colegio' => 'El local de votación seleccionado no es válido.'])->withInput();
        }

        $depa = Departamento::find($request->co_departamento);
        $prov = Provincia::find($request->co_provincia);
        $dist = Distrito::find($request->co_distrito);
        $partido = Partido::find($request->cod_org_politica);

        $dirColegio = (!empty($colegio->dir_colegio) && $colegio->dir_colegio !== '.') 
            ? $colegio->dir_colegio 
            : null;

        $personero->update([
            'dni' => $request->dni,
            'nombre' => mb_strtoupper(trim($request->nombre), 'UTF-8'),
            'sexo' => $request->sexo,
            'celular' => $request->celular,
            'correo' => $request->correo,
            'co_departamento' => $request->co_departamento,
            'desc_departamento' => $depa?->name ?? $personero->desc_departamento,
            'co_provincia' => $request->co_provincia,
            'desc_provincia' => $prov?->name ?? $personero->desc_provincia,
            'co_distrito' => $request->co_distrito,
            'desc_distrito' => $dist?->name ?? $personero->desc_distrito,
            'direccion' => $request->direccion,
            'cod_org_politica' => $request->cod_org_politica,
            'desc_org_politica' => $partido?->des_partido ?? $personero->desc_org_politica,
            'desc_tipo_personero' => $request->desc_tipo_personero,
            'desc_centro_vota' => $request->desc_centro_vota,
            'nro_mesa' => $request->filled('nro_mesa') ? $request->nro_mesa : null,
            'nro_credencial' => $request->nro_credencial,
            'fecha_acreditacion' => $request->fecha_acreditacion,
            'estado_personero' => $request->estado_personero,
            'estado_credencial' => $request->estado_credencial,
            'observacion' => $request->observacion,
            'cod_local' => $request->cod_local,
            'cod_colegio' => trim($request->cod_colegio),
            'cod_ubigeo_cole' => trim($request->cod_ubigeo_cole),
            'dir_colegio' => $dirColegio,
        ]);

        return redirect()->route('personeros.list')->with('success', 'El registro se ha actualizado correctamente.');
    }

    public function destroy(Personero $personero)
    {
        $personero->delete();
        return redirect()->route('personeros.list')->with('success', 'Registro eliminado correctamente.');
    }

    public function store(Request $request)
    {
        if (!$request->filled('correo')) {
            $request->merge(['correo' => 'sincorreo@gmail.com']);
        }

        if ($request->input('correo') === 'sincorreo@gmail.com' && $request->filled('dni')) {
            $request->merge(['correo' => 'sincorreo+' . $request->input('dni') . '@gmail.com']);
        }

        if ($request->filled('co_distrito') && !$request->filled('cod_ubigeo_cole')) {
            $request->merge(['cod_ubigeo_cole' => $request->co_distrito]);
        }

        $request->validate([
            'dni' => 'required|numeric|digits:8|unique:personeros,dni',
            'nombre' => 'required|string|max:255',
            'sexo' => 'required|in:Masculino,Femenino',
            'celular' => 'required|numeric|digits:9',
            'correo' => 'required|email|max:120|unique:personeros,correo',
            'co_departamento' => 'required',
            'co_provincia' => 'required',
            'co_distrito' => 'required',
            'direccion' => 'required|max:180',
            'cod_org_politica' => 'required',
            'desc_tipo_personero' => 'required|max:50',
            'desc_centro_vota' => 'required|max:180',
            'nro_mesa' => 'nullable|numeric|max_digits:8',
            'cod_local' => 'required|exists:locales,cod_local',
            'cod_colegio' => 'required|string|max:6',
            'cod_ubigeo_cole' => 'required|string|max:6',
        ], [
            'dni.unique' => 'El número de DNI ya se encuentra registrado.',
            'dni.required' => 'El campo DNI es obligatorio.',
            'sexo.required' => 'Debe seleccionar el sexo.',
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
            'dni.numeric' => 'El DNI debe contener solo números.',
            'celular.required' => '¡Completa este campo!',
            'celular.numeric' => 'El celular debe contener solo números.',
            'celular.digits' => 'El NRO CELULAR debe tener 9 dígitos.',
            'nro_mesa.numeric' => 'La mesa debe contener solo números.',
            'correo.unique' => 'El correo electrónico ya se encuentra registrado.',
            'correo.email' => 'Ingrese un formato de correo válido.',
            'correo.required' => 'El campo correo es obligatorio.',
            'cod_colegio.required' => 'El local de votación (colegio) es obligatorio.',
            'cod_ubigeo_cole.required' => 'El código de ubigeo del colegio es obligatorio.',
        ]);

        $colegio = Colegio::where('cod_colegio', trim($request->cod_colegio))
            ->where('cod_ubigeo_cole', trim($request->cod_ubigeo_cole))
            ->first();
        if (!$colegio && $request->filled('co_distrito')) {
            $colegio = Colegio::where('cod_colegio', trim($request->cod_colegio))
                ->where('cod_ubigeo_cole', trim($request->co_distrito))
                ->first();
        }
        if (!$colegio) {
            return redirect()->back()->withErrors(['cod_colegio' => 'El local de votación seleccionado no es válido.'])->withInput();
        }

        $depa = Departamento::find($request->co_departamento);
        $prov = Provincia::find($request->co_provincia);
        $dist = Distrito::find($request->co_distrito);
        $partido = Partido::find($request->cod_org_politica);

        $dirColegio = (!empty($colegio->dir_colegio) && $colegio->dir_colegio !== '.') 
            ? $colegio->dir_colegio 
            : null;

        $personero = Personero::create([
            'dni' => $request->dni,
            'nombre' => mb_strtoupper(trim($request->nombre), 'UTF-8'),
            'sexo' => $request->sexo,
            'celular' => $request->celular,
            'correo' => $request->correo,
            'co_departamento' => $request->co_departamento,
            'desc_departamento' => $depa?->name ?? '',
            'co_provincia' => $request->co_provincia,
            'desc_provincia' => $prov?->name ?? '',
            'co_distrito' => $request->co_distrito,
            'desc_distrito' => $dist?->name ?? '',
            'direccion' => $request->direccion,
            'cod_org_politica' => $request->cod_org_politica,
            'desc_org_politica' => $partido?->des_partido ?? '',
            'desc_tipo_personero' => $request->desc_tipo_personero,
            'desc_centro_vota' => $request->desc_centro_vota,
            'nro_mesa' => $request->filled('nro_mesa') ? $request->nro_mesa : null,
            'cod_local' => $request->cod_local,
            'cod_colegio' => trim($request->cod_colegio),
            'cod_ubigeo_cole' => trim($request->cod_ubigeo_cole),
            'dir_colegio' => $dirColegio,
        ]);

        return redirect()->back()->with('success', 'Gracias, recibimos tu solicitud como personero.')->with('submitted_data', $personero);
    }

    public function getProvincias($cod_depa)
    {
        return response()->json(Provincia::where('cod_ubigeo_depa', $cod_depa)->orderBy('name')->get());
    }

    public function getDistritos($cod_prov)
    {
        return response()->json(Distrito::where('cod_ubigeo_prov', $cod_prov)->orderBy('name')->get());
    }

    public function getColegios($cod_distrito)
    {
        return response()->json(Colegio::where('cod_ubigeo_cole', trim($cod_distrito))->orderBy('des_local_cole')->get());
    }

    /**
     * Descarga individual de credencial PDF para un personero
     */
    public function descargarCredencial(Personero $personero, CredencialPdfService $pdfService)
    {
        if (trim((string)$personero->estado_credencial) !== 'Si') {
            return redirect()->back()->with('error', 'La credencial de este personero no se encuentra habilitada para impresión (estado_credencial = No).');
        }

        try {
            $pdfContent = $pdfService->generateSingle($personero);

            // Actualizar estado_descarga a 'Si' únicamente tras la generación correcta
            $personero->update(['estado_descarga' => 'Si']);

            $dni = $personero->dni ?: $personero->id;
            $filename = "credencial_{$dni}.pdf";

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al generar la credencial: ' . $e->getMessage());
        }
    }

    /**
     * Descarga masiva de credenciales PDF en un único archivo multipágina
     */
    public function descargarCredencialesMasivo(Request $request, CredencialPdfService $pdfService)
    {
        $ids = $request->input('ids');
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }

        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->with('error', 'Debe seleccionar al menos un personero para descargar las credenciales.');
        }

        try {
            // Filtrar estrictamente solo los registros autorizados para impresión (estado_credencial = 'Si')
            $personeros = Personero::whereIn('id', $ids)
                ->where('estado_credencial', 'Si')
                ->orderBy('created_at', 'desc')
                ->get();

            if ($personeros->isEmpty()) {
                return redirect()->back()->with('error', 'Ninguno de los personeros seleccionados está habilitado para impresión de credenciales (estado_credencial = Si).');
            }

            $pdfContent = $pdfService->generateBatch($personeros);

            // Actualizar estado_descarga a 'Si' únicamente para los personeros autorizados generados
            Personero::whereIn('id', $personeros->pluck('id'))->update(['estado_descarga' => 'Si']);

            $filename = 'credenciales_personeros.pdf';

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al generar las credenciales masivas: ' . $e->getMessage());
        }
    }

    /**
     * Actualiza asíncronamente el estado_check_whatsapp de un personero (Control Manual de WhatsApp)
     */
    public function toggleCheckWhatsapp(Request $request, Personero $personero)
    {
        $request->validate([
            'estado' => 'required|in:1,0,true,false,Si,No,SI,NO',
        ]);

        $isChecked = filter_var($request->input('estado'), FILTER_VALIDATE_BOOLEAN)
            || in_array($request->input('estado'), ['1', 'Si', 'SI', 'S'], true);

        $nuevoEstado = $isChecked ? '1' : null;

        $personero->update([
            'estado_check_whatsapp' => $nuevoEstado,
        ]);

        return response()->json([
            'success' => true,
            'id' => $personero->id,
            'estado_check_whatsapp' => $personero->estado_check_whatsapp,
            'is_checked' => $isChecked,
            'message' => $isChecked ? 'Marcado para grupo de WhatsApp.' : 'Desmarcado de grupo de WhatsApp.'
        ]);
    }

    /**
     * Actualiza asíncronamente el estado_credencial de un personero (Control Individual de Impresión)
     */
    public function toggleEstadoCredencial(Request $request, Personero $personero)
    {
        $request->validate([
            'estado' => 'required|in:1,0,true,false,Si,No,SI,NO',
        ]);

        $isChecked = filter_var($request->input('estado'), FILTER_VALIDATE_BOOLEAN)
            || in_array($request->input('estado'), ['1', 'Si', 'SI', 'S'], true);

        $nuevoEstado = $isChecked ? 'Si' : 'No';

        $personero->update([
            'estado_credencial' => $nuevoEstado,
        ]);

        return response()->json([
            'success' => true,
            'id' => $personero->id,
            'estado_credencial' => $personero->estado_credencial,
            'is_checked' => $isChecked,
            'message' => $isChecked ? 'Credencial habilitada para impresión.' : 'Credencial deshabilitada para impresión.'
        ]);
    }

    /**
     * Actualiza asíncronamente el estado_credencial de forma masiva (Control Masivo de Impresión)
     */
    public function toggleEstadoCredencialMasivo(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:personeros,id',
            'estado' => 'required|in:1,0,true,false,Si,No,SI,NO',
        ]);

        $isChecked = filter_var($request->input('estado'), FILTER_VALIDATE_BOOLEAN)
            || in_array($request->input('estado'), ['1', 'Si', 'SI', 'S'], true);

        $nuevoEstado = $isChecked ? 'Si' : 'No';
        $ids = $request->input('ids');

        $updatedCount = Personero::whereIn('id', $ids)->update([
            'estado_credencial' => $nuevoEstado,
        ]);

        return response()->json([
            'success' => true,
            'updated_count' => $updatedCount,
            'estado_credencial' => $nuevoEstado,
            'is_checked' => $isChecked,
            'message' => $isChecked 
                ? "Se habilitaron {$updatedCount} registros para impresión." 
                : "Se deshabilitaron {$updatedCount} registros para impresión."
        ]);
    }
}
