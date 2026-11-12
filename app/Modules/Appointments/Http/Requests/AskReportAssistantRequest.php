<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\ReportDateDefaults;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AskReportAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ReportDateDefaults::apply($this->all()));
    }

    public function rules(): array
    {
        $types = 'citas_fecha,citas_medico,citas_especialidad,citas_estado,pacientes,totales_periodo,agenda_diaria';
        $type = (string) $this->input('tipo', '');
        $action = (string) $this->input('accion', 'resumir');
        $rules = [
            'tipo' => ['required', 'in:'.$types],
            'accion' => ['required', 'in:resumir,preguntar'],
            'pregunta' => [$action === 'preguntar' ? 'required' : 'nullable', 'string', 'max:500'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            'id_medico' => ['nullable', 'integer'],
            'id_especialidad' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:Programada,Confirmada,Atendida,Cancelada,Inasistencia'],
        ];

        return match ($type) {
            'citas_fecha', 'agenda_diaria' => array_merge($rules, [
                'fecha' => ['required', 'date_format:Y-m-d'],
            ]),
            'citas_medico' => array_merge($rules, [
                'id_medico' => ['required', 'integer'],
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['required', 'date_format:Y-m-d'],
            ]),
            'citas_especialidad' => array_merge($rules, [
                'id_especialidad' => ['required', 'integer'],
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['required', 'date_format:Y-m-d'],
            ]),
            'citas_estado' => array_merge($rules, [
                'estado' => ['required', 'in:Programada,Confirmada,Atendida,Cancelada,Inasistencia'],
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['required', 'date_format:Y-m-d'],
            ]),
            'totales_periodo' => array_merge($rules, [
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['required', 'date_format:Y-m-d'],
            ]),
            default => $rules,
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('fecha_inicio');
            $end = $this->input('fecha_fin');
            if (is_string($start) && is_string($end) && $start !== '' && $end !== '' && $start > $end) {
                $validator->errors()->add('fecha_inicio', 'La fecha inicial no puede ser posterior a la fecha final.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Seleccione el tipo de reporte.',
            'tipo.in' => 'El tipo de reporte no es válido.',
            'accion.required' => 'Indique la acción del asistente.',
            'accion.in' => 'La acción del asistente no es válida.',
            'pregunta.required' => 'Escriba una pregunta sobre el reporte.',
            'pregunta.max' => 'La pregunta es demasiado larga.',
            'fecha.required' => 'Indique la fecha del reporte.',
            'fecha_inicio.required' => 'Indique la fecha inicial.',
            'fecha_fin.required' => 'Indique la fecha final.',
            'id_medico.required' => 'Seleccione el médico del reporte.',
            'id_especialidad.required' => 'Seleccione la especialidad del reporte.',
            'estado.required' => 'Seleccione el estado del reporte.',
        ];
    }
}
