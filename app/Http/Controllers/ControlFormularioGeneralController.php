<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\Provincia;
use App\Models\Distrito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ControlFormularioGeneralController extends Controller
{
    /**
     * Muestra la vista de parametrización territorial (TreeView) para /formulario-general.
     */
    public function index(Request $request)
    {
        // Estadísticas generales para los widgets superiores
        $totalDepas = Departamento::count();
        $activosDepas = Departamento::where('stat_vista_depa', '1')->count();

        $totalProvs = Provincia::count();
        $activosProvs = Provincia::where('stat_vista_prov', '1')->count();

        $totalDists = Distrito::count();
        $activosDists = Distrito::where('stat_vista_dist', '1')->count();

        // Total distritos con visibilidad efectiva en /formulario-general
        $efectivosDists = Distrito::join('provincias', 'distritos.cod_ubigeo_prov', '=', 'provincias.cod_ubigeo')
            ->join('departamentos', 'provincias.cod_ubigeo_depa', '=', 'departamentos.cod_ubigeo')
            ->where('distritos.stat_vista_dist', '1')
            ->where('provincias.stat_vista_prov', '1')
            ->where('departamentos.stat_vista_depa', '1')
            ->count();

        // Filtros disponibles para los selects superiores
        $departamentosList = Departamento::orderBy('name')->get(['cod_ubigeo', 'name', 'stat_vista_depa']);
        $provinciasList = collect();
        $distritosList = collect();

        if ($request->filled('co_departamento')) {
            $provinciasList = Provincia::where('cod_ubigeo_depa', $request->co_departamento)->orderBy('name')->get();
        }

        if ($request->filled('co_provincia')) {
            $distritosList = Distrito::where('cod_ubigeo_prov', $request->co_provincia)->orderBy('name')->get();
        }

        // Construcción de la consulta del TreeView
        $query = Departamento::with(['provincias' => function ($q) use ($request) {
            if ($request->filled('co_provincia')) {
                $q->where('cod_ubigeo', $request->co_provincia);
            }
            $q->orderBy('name');
            $q->with(['distritos' => function ($q2) use ($request) {
                if ($request->filled('co_distrito')) {
                    $q2->where('cod_ubigeo', $request->co_distrito);
                }
                if ($request->filled('nombre')) {
                    $termino = trim($request->nombre);
                    $q2->where(function($subQ) use ($termino) {
                        $subQ->where('name', 'like', '%' . $termino . '%')
                             ->orWhere('cod_ubigeo', 'like', '%' . $termino . '%');
                    });
                }
                $q2->orderBy('name');
            }]);
        }]);

        if ($request->filled('co_departamento')) {
            $query->where('cod_ubigeo', $request->co_departamento);
        }

        if ($request->filled('nombre')) {
            $termino = trim($request->nombre);
            $query->where(function ($q) use ($termino) {
                $q->where('name', 'like', '%' . $termino . '%')
                  ->orWhere('cod_ubigeo', 'like', '%' . $termino . '%')
                  ->orWhereHas('provincias', function ($qp) use ($termino) {
                      $qp->where('name', 'like', '%' . $termino . '%')
                         ->orWhere('cod_ubigeo', 'like', '%' . $termino . '%')
                         ->orWhereHas('distritos', function ($qd) use ($termino) {
                             $qd->where('name', 'like', '%' . $termino . '%')
                                ->orWhere('cod_ubigeo', 'like', '%' . $termino . '%');
                         });
                  });
            });
        }

        $tree = $query->orderBy('name')->get();

        return view('control_formulario.index', compact(
            'tree',
            'totalDepas',
            'activosDepas',
            'totalProvs',
            'activosProvs',
            'totalDists',
            'activosDists',
            'efectivosDists',
            'departamentosList',
            'provinciasList',
            'distritosList'
        ));
    }

    /**
     * Alterna de forma asíncrona (AJAX) el estado de activación de un Departamento, Provincia o Distrito.
     */
    public function toggleStatus(Request $request)
    {
        $validated = $request->validate([
            'level' => ['required', 'in:departamento,provincia,distrito'],
            'cod_ubigeo' => ['required', 'string', 'max:6'],
            'status' => ['required', 'in:0,1'],
        ]);

        try {
            $level = $validated['level'];
            $codUbigeo = trim($validated['cod_ubigeo']);
            $newStatus = (string) $validated['status'];

            $nombre = '';

            DB::transaction(function () use ($level, $codUbigeo, $newStatus, &$nombre) {
                if ($level === 'departamento') {
                    $dep = Departamento::where('cod_ubigeo', $codUbigeo)->firstOrFail();
                    $dep->stat_vista_depa = $newStatus;
                    $dep->save();
                    $nombre = $dep->name;

                    // Al activar a nivel de departamento, activar todas sus provincias y distritos hijos
                    if ($newStatus === '1') {
                        $provUbigeos = Provincia::where('cod_ubigeo_depa', $codUbigeo)->pluck('cod_ubigeo');
                        Provincia::where('cod_ubigeo_depa', $codUbigeo)->update(['stat_vista_prov' => '1']);
                        Distrito::whereIn('cod_ubigeo_prov', $provUbigeos)->update(['stat_vista_dist' => '1']);
                    }
                } elseif ($level === 'provincia') {
                    $prov = Provincia::where('cod_ubigeo', $codUbigeo)->firstOrFail();
                    $prov->stat_vista_prov = $newStatus;
                    $prov->save();
                    $nombre = $prov->name;

                    // Al activar a nivel de provincia, activar todos sus distritos hijos
                    if ($newStatus === '1') {
                        Distrito::where('cod_ubigeo_prov', $codUbigeo)->update(['stat_vista_dist' => '1']);
                    }
                } elseif ($level === 'distrito') {
                    $dist = Distrito::where('cod_ubigeo', $codUbigeo)->firstOrFail();
                    $dist->stat_vista_dist = $newStatus;
                    $dist->save();
                    $nombre = $dist->name;
                }
            });

            // Recalcular métricas en tiempo real
            $activosDepas = Departamento::where('stat_vista_depa', '1')->count();
            $activosProvs = Provincia::where('stat_vista_prov', '1')->count();
            $activosDists = Distrito::where('stat_vista_dist', '1')->count();
            $efectivosDists = Distrito::join('provincias', 'distritos.cod_ubigeo_prov', '=', 'provincias.cod_ubigeo')
                ->join('departamentos', 'provincias.cod_ubigeo_depa', '=', 'departamentos.cod_ubigeo')
                ->where('distritos.stat_vista_dist', '1')
                ->where('provincias.stat_vista_prov', '1')
                ->where('departamentos.stat_vista_depa', '1')
                ->count();

            return response()->json([
                'success' => true,
                'message' => ucfirst($level) . " '{$nombre}' " . ($newStatus === '1' ? 'activado' : 'desactivado') . ' correctamente.',
                'new_status' => $newStatus,
                'level' => $level,
                'cod_ubigeo' => $codUbigeo,
                'stats' => [
                    'activosDepas' => $activosDepas,
                    'activosProvs' => $activosProvs,
                    'activosDists' => $activosDists,
                    'efectivosDists' => $efectivosDists,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar estado en ControlFormularioGeneralController: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al actualizar el estado: ' . $e->getMessage(),
            ], 500);
        }
    }
}
