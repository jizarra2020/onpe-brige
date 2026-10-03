<?php

namespace App\Http\Controllers;

use App\Http\Requests\FormularioPublicoRequest;
use App\Models\Departamento;
use App\Models\Provincia;
use App\Models\Distrito;
use App\Models\Partido;
use App\Models\Colegio;
use App\Models\Personero;
use App\Services\DniLookupService;
use App\Services\Onpe\OnpeConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class FormularioPublicoController extends Controller
{
    /**
     * Muestra el formulario público de registro para personeros.
     */
    public function index()
    {
        $departamentos = Departamento::orderBy('name')->get();
        $partidos = Partido::orderBy('des_partido')->get();

        return view('formulario.formulario', compact('departamentos', 'partidos'));
    }

    /**
     * Muestra el formulario público de registro general para personeros.
     */
    public function formularioGeneral()
    {
        $departamentos = Departamento::orderBy('name')->get();
        $distritos = Distrito::select(
                'distritos.cod_ubigeo',
                'distritos.name',
                'distritos.cod_ubigeo_prov',
                'provincias.name as provincia_name',
                'departamentos.name as departamento_name'
            )
            ->join('provincias', 'distritos.cod_ubigeo_prov', '=', 'provincias.cod_ubigeo')
            ->join('departamentos', 'provincias.cod_ubigeo_depa', '=', 'departamentos.cod_ubigeo')
            ->where('distritos.stat_vista_dist', '1')
            ->where('provincias.stat_vista_prov', '1')
            ->where('departamentos.stat_vista_depa', '1')
            ->orderBy('distritos.name', 'asc')
            ->get();

        $distritosArray = $distritos->map(fn($d) => [
            'id' => (string) $d->cod_ubigeo,
            'name' => (string) $d->name,
            'provincia' => (string) ($d->provincia_name ?? 'LIMA'),
            'departamento' => (string) ($d->departamento_name ?? 'LIMA'),
        ])->values();

        return view('formulario.formulariogeneral', compact('departamentos', 'distritos', 'distritosArray'));
    }

    /**
     * Almacena el registro del formulario general en la base de datos.
     */
    public function storeFormularioGeneral(FormularioPublicoRequest $request)
    {
        return $this->store($request);
    }

    /**
     * Muestra el formulario público de registro general ONPE para personeros.
     */
    public function formularioGeneralOnpe()
    {
        $departamentos = Departamento::orderBy('name')->get();
        $distritos = Distrito::select(
                'distritos.cod_ubigeo',
                'distritos.name',
                'distritos.cod_ubigeo_prov',
                'provincias.name as provincia_name',
                'departamentos.name as departamento_name'
            )
            ->join('provincias', 'distritos.cod_ubigeo_prov', '=', 'provincias.cod_ubigeo')
            ->join('departamentos', 'provincias.cod_ubigeo_depa', '=', 'departamentos.cod_ubigeo')
            ->where('distritos.stat_vista_dist', '1')
            ->where('provincias.stat_vista_prov', '1')
            ->where('departamentos.stat_vista_depa', '1')
            ->orderBy('distritos.name', 'asc')
            ->get();

        $distritosArray = $distritos->map(fn($d) => [
            'id' => (string) $d->cod_ubigeo,
            'name' => (string) $d->name,
            'provincia' => (string) ($d->provincia_name ?? 'LIMA'),
            'departamento' => (string) ($d->departamento_name ?? 'LIMA'),
        ])->values();

        return view('formulario.formulariogeneralonpe', compact('departamentos', 'distritos', 'distritosArray'));
    }

    /**
     * Almacena el registro del formulario general ONPE en la base de datos.
     */
    public function storeFormularioGeneralOnpe(FormularioPublicoRequest $request)
    {
        return $this->store($request);
    }

    /**
     * Almacena el registro del formulario público en la base de datos.
     */
    public function store(FormularioPublicoRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $cleanDni = preg_replace('/[^0-9]/', '', $request->dni);
                $cleanCelular = preg_replace('/[^0-9]/', '', $request->celular);
                $cleanNombre = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $request->nombre)), 'UTF-8');

                // Verificación de duplicidad previa para mensaje claro
                $existingPersonero = Personero::where('dni', $cleanDni)->first();
                if ($existingPersonero) {
                    return redirect()->back()
                        ->withErrors(['dni' => 'El DNI ' . $cleanDni . ' ya se encuentra registrado previamente en el sistema.'])
                        ->withInput();
                }

                // Resolución dinámica de Distrito, Provincia y Departamento vía Eloquent (Sin hardcoding)
                $coDistrito = $request->input('co_distrito');
                $coProvincia = $request->input('co_provincia');
                $coDepartamento = $request->input('co_departamento');

                $dist = Distrito::with('provincia.departamento')->find($coDistrito);
                $prov = $dist?->provincia ?? Provincia::find($coProvincia);
                $depa = $prov?->departamento ?? Departamento::find($coDepartamento);

                $isGeneral = ($request->route() && ($request->routeIs('formulario-general*') || $request->routeIs('formulario-general-onpe*'))) 
                          || $request->is('formulario-general*') 
                          || $request->is('formulario-general-onpe*') 
                          || !$request->filled('sexo');

                // Resolución dinámica del Partido Político (Solo para formulario regular, en general es NULL)
                $codOrg = null;
                $partido = null;
                if (!$isGeneral) {
                    $codOrg = $request->input('cod_org_politica');
                    if (!$codOrg) {
                        $partidoRenaces = Partido::where('des_partido', 'LIKE', '%RENACE%')->first() 
                                     ?? Partido::where('cod_partido', '!=', '00')->first();
                        $codOrg = $partidoRenaces?->cod_partido;
                    }
                    $partido = $codOrg ? Partido::find($codOrg) : null;
                }

                $codColegio = $request->filled('cod_colegio') ? trim($request->input('cod_colegio')) : null;
                $codUbigeoCole = $request->filled('cod_ubigeo_cole') ? trim($request->input('cod_ubigeo_cole')) : ($coDistrito ?? null);
                $descCentroVota = null;
                $dirColegio = null;

                if ($codColegio) {
                    $colegio = null;
                    if ($codUbigeoCole) {
                        $colegio = Colegio::where('cod_colegio', $codColegio)
                            ->where('cod_ubigeo_cole', $codUbigeoCole)
                            ->first();
                    }
                    if (!$colegio && $coDistrito) {
                        $colegio = Colegio::where('cod_colegio', $codColegio)
                            ->where('cod_ubigeo_cole', $coDistrito)
                            ->first();
                    }
                    if ($colegio) {
                        $descCentroVota = $colegio->des_local_cole;
                        $dirColegio = (!empty($colegio->dir_colegio) && $colegio->dir_colegio !== '.') 
                            ? $colegio->dir_colegio 
                            : null;
                    }
                }

                if ($request->filled('custom_colegio')) {
                    $customVal = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $request->input('custom_colegio'))), 'UTF-8');
                    if ($customVal !== '') {
                        $descCentroVota = $customVal;
                    }
                }

                if ($request->filled('dir_colegio')) {
                    $refInput = trim((string)$request->input('dir_colegio'));
                    if ($refInput !== '' && strtolower($refInput) !== 'null' && strtolower($refInput) !== 'undefined') {
                        $dirColegio = mb_strtoupper(preg_replace('/\s+/', ' ', $refInput), 'UTF-8');
                    }
                }

                if (empty($descCentroVota)) {
                    return redirect()->back()
                        ->withErrors(['cod_colegio' => 'El centro de votación es obligatorio. Por favor seleccione o ingrese su centro de votación.'])
                        ->withInput();
                }

                // Generar correo por defecto compatible con la restricción UNIQUE de la BD
                $correoGenerado = 'sincorreo+' . $cleanDni . '@gmail.com';

                $personero = Personero::create([
                    'dni' => $cleanDni,
                    'nombre' => $cleanNombre,
                    'sexo' => $isGeneral ? null : $request->input('sexo'),
                    'celular' => $cleanCelular,
                    'correo' => $correoGenerado,
                    'co_departamento' => $depa?->cod_ubigeo ?? ($coDepartamento ?? '140000'),
                    'desc_departamento' => $depa?->name ?? 'LIMA',
                    'co_provincia' => $prov?->cod_ubigeo ?? ($coProvincia ?? '140100'),
                    'desc_provincia' => $prov?->name ?? 'LIMA',
                    'co_distrito' => $dist?->cod_ubigeo ?? $coDistrito,
                    'desc_distrito' => $dist?->name ?? '',
                    'direccion' => null,
                    'cod_org_politica' => $isGeneral ? null : ($partido?->cod_partido ?? $codOrg),
                    'desc_org_politica' => $isGeneral ? null : ($partido?->des_partido ?? null),
                    'desc_tipo_personero' => $request->input('desc_tipo_personero', 'Personero de mesa'),
                    'desc_centro_vota' => $descCentroVota,
                    'nro_mesa' => $request->filled('nro_mesa') ? $request->input('nro_mesa') : null,
                    'nro_credencial' => null,
                    'fecha_acreditacion' => null,
                    'estado_personero' => 'Pendiente',
                    'estado_credencial' => null,
                    'observacion' => 'Registro ingresado desde formulario público web.',
                    'cod_local' => null,
                    'cod_colegio' => $codColegio,
                    'cod_ubigeo_cole' => $codUbigeoCole,
                    'dir_colegio' => $dirColegio,
                    'creator' => 0,
                    'status' => 1,
                ]);

                return redirect()->back()
                    ->with('success', '¡Bienvenido su Solicitud recibida correctamente!')
                    ->with('submitted_data', $personero);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('QueryException al guardar solicitud pública de personero: ' . $e->getMessage(), [
                'input' => $request->except(['_token'])
            ]);

            if ($e->errorInfo[1] == 1062) {
                return redirect()->back()
                    ->withErrors(['dni' => 'El número de DNI ya se encuentra registrado en el sistema.'])
                    ->withInput();
            }

            return redirect()->back()
                ->withErrors(['general' => 'Ocurrió un error al registrar los datos en la base de datos. Por favor revise los campos e intente de nuevo.'])
                ->withInput();
        } catch (\Exception $e) {
            Log::error('Error general al guardar solicitud pública de personero: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['_token'])
            ]);

            return redirect()->back()
                ->withErrors(['general' => 'Ocurrió un error inesperado al procesar su solicitud. Por favor intente nuevamente.'])
                ->withInput();
        }
    }

    /**
     * API Pública: Obtiene provincias por departamento.
     */
    public function getProvincias($cod_depa)
    {
        $provincias = Provincia::where('cod_ubigeo_depa', $cod_depa)
            ->orderBy('name')
            ->get(['cod_ubigeo', 'name', 'cod_ubigeo_depa']);

        return response()->json($provincias);
    }

    /**
     * API Pública: Obtiene distritos por provincia.
     */
    public function getDistritos($cod_prov)
    {
        $distritos = Distrito::where('cod_ubigeo_prov', $cod_prov)
            ->orderBy('name')
            ->get(['cod_ubigeo', 'name', 'cod_ubigeo_prov']);

        return response()->json($distritos);
    }

    /**
     * API Pública: Obtiene colegios por ubigeo de distrito.
     */
    public function getColegios($cod_distrito)
    {
        $colegios = Colegio::where('cod_ubigeo_cole', trim($cod_distrito))
            ->orderBy('des_local_cole')
            ->get(['cod_colegio', 'cod_ubigeo_cole', 'des_local_cole', 'dir_colegio']);

        return response()->json($colegios);
    }

    /**
     * API Pública: Consulta nombre por DNI con soporte de Modo Automático y Fallback Manual.
     */
    public function consultarDni(string $dni, OnpeConsultaService $onpeService)
    {
        $result = $onpeService->consultar($dni);

        return response()->json($result, 200);
    }
}


