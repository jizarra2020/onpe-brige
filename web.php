<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PersoneroController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ColegioController;
use App\Http\Controllers\FormularioPublicoController;
use App\Http\Controllers\ControlFormularioGeneralController;
use App\Http\Controllers\DescargaCertificadoController;
use App\Http\Controllers\OnpeConsultaController;

// Rutas Públicas - Formulario de Registro de Personeros (Original, General y General ONPE)
Route::get('/formulario', [FormularioPublicoController::class, 'index'])->name('formulario.index');
Route::post('/formulario', [FormularioPublicoController::class, 'store'])->name('formulario.store');

Route::get('/formulario-general', [FormularioPublicoController::class, 'formularioGeneral'])->name('formulario-general.index');
Route::post('/formulario-general', [FormularioPublicoController::class, 'storeFormularioGeneral'])->name('formulario-general.store');

Route::get('/formulario-general-onpe', [FormularioPublicoController::class, 'formularioGeneralOnpe'])->name('formulario-general-onpe.index');
Route::post('/formulario-general-onpe', [FormularioPublicoController::class, 'storeFormularioGeneralOnpe'])->name('formulario-general-onpe.store');

// Rutas Públicas - Consulta y Descarga de Certificado / Credencial
Route::get('/descarga-certificado', [DescargaCertificadoController::class, 'index'])->name('descarga-certificado.index');
Route::post('/descarga-certificado/consultar', [DescargaCertificadoController::class, 'consultar'])->name('descarga-certificado.consultar');
Route::get('/descarga-certificado/{dni}/descargar', [DescargaCertificadoController::class, 'descargar'])->name('descarga-certificado.descargar');


// APIs públicas para combos dependientes (Ubigeo y Colegios) y Consulta DNI ONPE
Route::get('/api/provincias/{cod_depa}', [FormularioPublicoController::class, 'getProvincias'])->name('api.provincias');
Route::get('/api/distritos/{cod_prov}', [FormularioPublicoController::class, 'getDistritos'])->name('api.distritos');
Route::get('/api/colegios/{cod_distrito}', [FormularioPublicoController::class, 'getColegios'])->name('api.colegios');
Route::get('/api/consultar-dni/{dni}', [OnpeConsultaController::class, 'consultar'])->name('api.consultar-dni');
Route::post('/api/onpe/normalizar', [OnpeConsultaController::class, 'normalizar'])->name('api.onpe.normalizar');
Route::post('/api/onpe/sync-waf-token', [OnpeConsultaController::class, 'syncWafToken'])->name('api.onpe.sync-waf');
Route::get('/api/onpe/status-waf-token', [OnpeConsultaController::class, 'statusWafToken'])->name('api.onpe.status-waf');


// Rutas de Autenticación
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Rutas Protegidas por Autenticación
Route::middleware('auth')->group(function () {
    
    // Dashboard / Resumen General (requiere permiso ver-dashboard o similar, let's grant to all authenticated roles)
    Route::get('/', [PersoneroController::class, 'dashboard'])
        ->middleware('can:ver-dashboard')
        ->name('personeros.dashboard');

    // Listado General y Exportación
    Route::get('/personeros/listado', [PersoneroController::class, 'list'])
        ->middleware('can:ver-listado')
        ->name('personeros.list');

    Route::get('/personeros/exportar', [PersoneroController::class, 'export'])
        ->middleware('can:exportar-listado')
        ->name('personeros.export');

    // Control Manual de WhatsApp (Check listado)
    Route::post('/personeros/{personero}/toggle-check-whatsapp', [PersoneroController::class, 'toggleCheckWhatsapp'])
        ->middleware('can:ver-listado')
        ->name('personeros.toggleCheckWhatsapp');

    // Control de Impresión de Credencial (Individual y Masivo)
    Route::post('/personeros/{personero}/toggle-estado-credencial', [PersoneroController::class, 'toggleEstadoCredencial'])
        ->middleware('can:ver-listado')
        ->name('personeros.toggleEstadoCredencial');

    Route::post('/personeros/toggle-estado-credencial-masivo', [PersoneroController::class, 'toggleEstadoCredencialMasivo'])
        ->middleware('can:ver-listado')
        ->name('personeros.toggleEstadoCredencialMasivo');

    // Generación y Descarga de Credenciales PDF (Individual y Masiva)
    Route::get('/personeros/{personero}/credencial', [PersoneroController::class, 'descargarCredencial'])
        ->middleware('can:ver-listado')
        ->name('personeros.credencial.individual');

    Route::post('/personeros/credenciales/masivo', [PersoneroController::class, 'descargarCredencialesMasivo'])
        ->middleware('can:ver-listado')
        ->name('personeros.credencial.masivo');

    // Registro de Nuevos Personeros (Panel Administrativo)
    Route::get('/personeros/registrar', [PersoneroController::class, 'index'])
        ->middleware('can:registrar-personero')
        ->name('personeros.index');

    Route::post('/personeros', [PersoneroController::class, 'store'])
        ->middleware('can:registrar-personero')
        ->name('personeros.store');

    // Edición y Actualización
    Route::get('/personeros/{personero}/editar', [PersoneroController::class, 'edit'])
        ->middleware('can:editar-personero')
        ->name('personeros.edit');

    Route::put('/personeros/{personero}', [PersoneroController::class, 'update'])
        ->middleware('can:editar-personero')
        ->name('personeros.update');

    // Eliminación
    Route::delete('/personeros/{personero}', [PersoneroController::class, 'destroy'])
        ->middleware('can:eliminar-personero')
        ->name('personeros.destroy');

    // CRUD de Usuarios (solo Administradores)
    Route::resource('users', UserController::class)
        ->middleware('can:gestionar-usuarios');

    // CRUD de Roles (solo Administradores)
    Route::resource('roles', RoleController::class)
        ->middleware('can:gestionar-usuarios');

    // CRUD de Colegios
    Route::get('/colegios', [ColegioController::class, 'index'])
        ->middleware('can:ver-colegios')
        ->name('colegios.index');

    Route::get('/colegios/crear', [ColegioController::class, 'create'])
        ->middleware('can:crear-colegios')
        ->name('colegios.create');

    Route::post('/colegios', [ColegioController::class, 'store'])
        ->middleware('can:crear-colegios')
        ->name('colegios.store');

    Route::get('/colegios/{cod_colegio}/{cod_ubigeo_cole}/editar', [ColegioController::class, 'edit'])
        ->middleware('can:editar-colegios')
        ->name('colegios.edit');

    Route::put('/colegios/{cod_colegio}/{cod_ubigeo_cole}', [ColegioController::class, 'update'])
        ->middleware('can:editar-colegios')
        ->name('colegios.update');

    Route::delete('/colegios/{cod_colegio}/{cod_ubigeo_cole}', [ColegioController::class, 'destroy'])
        ->middleware('can:eliminar-colegios')
        ->name('colegios.destroy');

    // Control Formulario General (TreeView Parametrizable)
    Route::get('/control-formulario-general', [ControlFormularioGeneralController::class, 'index'])
        ->middleware('can:ver-colegios')
        ->name('control-formulario.index');

    Route::post('/control-formulario-general/toggle', [ControlFormularioGeneralController::class, 'toggleStatus'])
        ->middleware('can:ver-colegios')
        ->name('control-formulario.toggle');
});
