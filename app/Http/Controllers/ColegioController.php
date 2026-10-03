<?php

namespace App\Http\Controllers;

use App\Models\Colegio;
use App\Models\Departamento;
use App\Models\Provincia;
use App\Models\Distrito;
use App\Models\Personero;
use Illuminate\Http\Request;

class ColegioController extends Controller
{
    /**
     * Muestra el listado de colegios con filtros.
     */
    public function index(Request $request)
    {
        $departamentos = Departamento::orderBy('name')->get();
        $provincias = [];
        $distritos = [];

        if ($request->filled('co_departamento')) {
            $provincias = Provincia::where('cod_ubigeo_depa', $request->co_departamento)->orderBy('name')->get();
        }

        if ($request->filled('co_provincia')) {
            $distritos = Distrito::where('cod_ubigeo_prov', $request->co_provincia)->orderBy('name')->get();
        }

        $query = Colegio::with('distrito.provincia.departamento');

        if ($request->filled('nombre')) {
            $query->where('des_local_cole', 'like', '%' . $request->nombre . '%');
        }

        if ($request->filled('co_distrito')) {
            $query->where('cod_ubigeo_cole', $request->co_distrito);
        } elseif ($request->filled('co_provincia')) {
            $query->whereIn('cod_ubigeo_cole', function ($q) use ($request) {
                $q->select('cod_ubigeo')
                  ->from('distritos')
                  ->where('cod_ubigeo_prov', $request->co_provincia);
            });
        } elseif ($request->filled('co_departamento')) {
            $query->whereIn('cod_ubigeo_cole', function ($q) use ($request) {
                $q->select('distritos.cod_ubigeo')
                  ->from('distritos')
                  ->join('provincias', 'distritos.cod_ubigeo_prov', '=', 'provincias.cod_ubigeo')
                  ->where('provincias.cod_ubigeo_depa', $request->co_departamento);
            });
        }

        $colegios = $query->orderBy('des_local_cole')->paginate(10)->withQueryString();

        return view('colegios.index', compact('colegios', 'departamentos', 'provincias', 'distritos'));
    }

    /**
     * Muestra el formulario de creación.
     */
    public function create()
    {
        $departamentos = Departamento::orderBy('name')->get();
        return view('colegios.create', compact('departamentos'));
    }

    /**
     * Guarda un nuevo colegio.
     */
    public function store(Request $request)
    {
        $request->validate([
            'cod_ubigeo_cole' => ['required', 'string', 'exists:distritos,cod_ubigeo'],
            'cod_colegio' => [
                'required',
                'string',
                'max:6',
                function ($attribute, $value, $fail) use ($request) {
                    $exists = Colegio::where('cod_colegio', trim($value))
                        ->where('cod_ubigeo_cole', trim($request->cod_ubigeo_cole))
                        ->exists();
                    if ($exists) {
                        $fail('La combinación de Código de Colegio y Distrito ya existe.');
                    }
                }
            ],
            'des_local_cole' => ['required', 'string', 'max:250'],
            'dir_colegio' => ['required', 'string', 'max:250'],
            'cantidad_mesas' => ['required', 'integer', 'min:1', 'max:999'],
            'status' => ['required', 'in:0,1'],
        ], [
            'cod_colegio.max' => 'El código de colegio no debe exceder los 6 caracteres.',
            'cod_colegio.required' => 'El código de colegio es obligatorio.',
            'cod_ubigeo_cole.required' => 'Debe seleccionar un distrito válido.',
            'des_local_cole.required' => 'El nombre del local del colegio es obligatorio.',
            'dir_colegio.required' => 'La dirección del colegio es obligatoria.',
            'cantidad_mesas.required' => 'La cantidad de mesas es obligatoria.',
            'cantidad_mesas.integer' => 'La cantidad de mesas debe ser un número entero.',
        ]);

        Colegio::create([
            'cod_colegio' => trim($request->cod_colegio),
            'cod_ubigeo_cole' => trim($request->cod_ubigeo_cole),
            'des_local_cole' => trim($request->des_local_cole),
            'dir_colegio' => trim($request->dir_colegio),
            'cantidad_mesas' => $request->cantidad_mesas,
            'creator' => auth()->id() ?? 0,
            'status' => $request->status,
        ]);

        return redirect()->route('colegios.index')
            ->with('success', 'Colegio creado exitosamente.');
    }

    /**
     * Muestra el formulario de edición.
     */
    public function edit($cod_colegio, $cod_ubigeo_cole)
    {
        $colegio = Colegio::where('cod_colegio', trim($cod_colegio))
            ->where('cod_ubigeo_cole', trim($cod_ubigeo_cole))
            ->firstOrFail();

        $departamentos = Departamento::orderBy('name')->get();

        $distrito = Distrito::where('cod_ubigeo', $colegio->cod_ubigeo_cole)->first();
        $cod_provincia = $distrito ? $distrito->cod_ubigeo_prov : null;

        $provincia = $cod_provincia ? Provincia::where('cod_ubigeo', $cod_provincia)->first() : null;
        $cod_departamento = $provincia ? $provincia->cod_ubigeo_depa : null;

        $provincias = $cod_departamento ? Provincia::where('cod_ubigeo_depa', $cod_departamento)->orderBy('name')->get() : [];
        $distritos = $cod_provincia ? Distrito::where('cod_ubigeo_prov', $cod_provincia)->orderBy('name')->get() : [];

        return view('colegios.edit', compact(
            'colegio',
            'departamentos',
            'provincias',
            'distritos',
            'cod_departamento',
            'cod_provincia'
        ));
    }

    /**
     * Actualiza los datos de un colegio.
     */
    public function update(Request $request, $cod_colegio, $cod_ubigeo_cole)
    {
        $colegio = Colegio::where('cod_colegio', trim($cod_colegio))
            ->where('cod_ubigeo_cole', trim($cod_ubigeo_cole))
            ->firstOrFail();

        $request->validate([
            'des_local_cole' => ['required', 'string', 'max:250'],
            'dir_colegio' => ['required', 'string', 'max:250'],
            'cantidad_mesas' => ['required', 'integer', 'min:1', 'max:999'],
            'status' => ['required', 'in:0,1'],
        ], [
            'des_local_cole.required' => 'El nombre del local del colegio es obligatorio.',
            'dir_colegio.required' => 'La dirección del colegio es obligatoria.',
            'cantidad_mesas.required' => 'La cantidad de mesas es obligatoria.',
            'cantidad_mesas.integer' => 'La cantidad de mesas debe ser un número entero.',
        ]);

        Colegio::where('cod_colegio', trim($cod_colegio))
            ->where('cod_ubigeo_cole', trim($cod_ubigeo_cole))
            ->update([
                'des_local_cole' => trim($request->des_local_cole),
                'dir_colegio' => trim($request->dir_colegio),
                'cantidad_mesas' => $request->cantidad_mesas,
                'status' => $request->status,
            ]);

        return redirect()->route('colegios.index')
            ->with('success', 'Colegio actualizado exitosamente.');
    }

    /**
     * Elimina un colegio.
     */
    public function destroy($cod_colegio, $cod_ubigeo_cole)
    {
        $hasPersoneros = Personero::where('cod_colegio', trim($cod_colegio))
            ->where('cod_ubigeo_cole', trim($cod_ubigeo_cole))
            ->exists();

        if ($hasPersoneros) {
            return redirect()->route('colegios.index')
                ->with('error', 'No se puede eliminar el colegio porque tiene personeros asignados.');
        }

        Colegio::where('cod_colegio', trim($cod_colegio))
            ->where('cod_ubigeo_cole', trim($cod_ubigeo_cole))
            ->delete();

        return redirect()->route('colegios.index')
            ->with('success', 'Colegio eliminado exitosamente.');
    }
}
