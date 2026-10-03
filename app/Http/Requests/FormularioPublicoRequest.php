<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FormularioPublicoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isGeneral = $this->routeIs('formulario-general*') 
                  || $this->routeIs('formulario-general-onpe*') 
                  || $this->is('formulario-general*') 
                  || $this->is('formulario-general-onpe*');

        return [
            'dni' => ['required', 'numeric', 'digits:8', 'unique:personeros,dni'],
            'nombre' => ['required', 'string', 'max:191'],
            'sexo' => $isGeneral ? ['nullable', 'in:Masculino,Femenino'] : ['required', 'in:Masculino,Femenino'],
            'celular' => ['required', 'numeric', 'digits:9'],
            'co_distrito' => ['required', 'string', 'exists:distritos,cod_ubigeo'],
            'co_departamento' => $isGeneral ? ['nullable', 'string', 'exists:departamentos,cod_ubigeo'] : ['required', 'string', 'exists:departamentos,cod_ubigeo'],
            'co_provincia' => $isGeneral ? ['nullable', 'string', 'exists:provincias,cod_ubigeo'] : ['required', 'string', 'exists:provincias,cod_ubigeo'],
            'cod_org_politica' => $isGeneral ? ['nullable', 'string', 'exists:partidos,cod_partido'] : ['required', 'string', 'exists:partidos,cod_partido'],
            'desc_tipo_personero' => ['required', 'string', 'in:Personero de mesa,Personero de centro de votación'],
            'cod_colegio' => ['required_without:custom_colegio', 'nullable', 'string', 'max:6'],
            'cod_ubigeo_cole' => ['nullable', 'string', 'max:6'],
            'custom_colegio' => ['required_without:cod_colegio', 'nullable', 'string', 'max:250'],
            'dir_colegio' => ['nullable', 'string', 'max:255'],
            'nro_mesa' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dni.required' => 'El número de DNI es obligatorio.',
            'dni.numeric' => 'El DNI debe contener únicamente números.',
            'dni.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.unique' => 'El número de DNI ya se encuentra registrado.',
            'nombre.required' => 'El nombre y apellidos son obligatorios.',
            'nombre.max' => 'El nombre no puede exceder los 191 caracteres.',
            'sexo.required' => 'Debe seleccionar el sexo.',
            'sexo.in' => 'El sexo seleccionado no es válido.',
            'celular.required' => 'El número de celular es obligatorio.',
            'celular.numeric' => 'El celular debe contener únicamente números.',
            'celular.digits' => 'El número de celular debe tener exactamente 9 dígitos.',
            'co_departamento.required' => 'Debe seleccionar un departamento.',
            'co_departamento.exists' => 'El departamento seleccionado no es válido.',
            'co_provincia.required' => 'Debe seleccionar una provincia.',
            'co_provincia.exists' => 'La provincia seleccionada no es válida.',
            'co_distrito.required' => 'Debe seleccionar un distrito.',
            'co_distrito.exists' => 'El distrito seleccionado no es válido.',
            'cod_colegio.required_without' => 'El centro de votación es obligatorio. Debe seleccionar o ingresar un centro.',
            'custom_colegio.required_without' => 'El centro de votación es obligatorio. Debe seleccionar o ingresar un centro.',
            'cod_org_politica.required' => 'Debe seleccionar una organización política.',
            'cod_org_politica.exists' => 'La organización política seleccionada no es válida.',
            'desc_tipo_personero.required' => 'Debe seleccionar el tipo de personero.',
            'desc_tipo_personero.in' => 'El tipo de personero seleccionado no es válido.',
        ];
    }
}
