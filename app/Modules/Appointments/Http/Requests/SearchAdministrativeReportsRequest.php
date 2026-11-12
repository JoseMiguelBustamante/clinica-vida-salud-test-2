<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SearchAdministrativeReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = 'citas_fecha,citas_medico,citas_especialidad,citas_estado,pacientes,totales_periodo,agenda_diaria';
        $generate = $this->input('generar') === '1';
        $type = (string) $this->input('tipo', '');
        $exporting = $this->routeIs('reports.export');
        $generate = $generate || $exporting;
        $rules = [
            'tipo' => [$generate ? 'required' : 'nullable', 'in:'.$types],
            'generar' => ['nullable', 'in:1'],
            'formato' => [$exporting ? 'required' : 'nullable', 'in:csv,excel,pdf'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            'id_medico' => ['nullable', 'integer'],
            'id_especialidad' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:Programada,Confirmada,Atendida,Cancelada,Inasistencia'],
        ];
        if (! $generate) {
            return $rules;
        }

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
            'fecha.required' => 'Indique la fecha del reporte.',
            'fecha.date_format' => 'Indique una fecha válida.',
            'fecha_inicio.required' => 'Indique la fecha inicial.',
            'fecha_inicio.date_format' => 'Indique una fecha inicial válida.',
            'fecha_fin.required' => 'Indique la fecha final.',
            'fecha_fin.date_format' => 'Indique una fecha final válida.',
            'id_medico.required' => 'Seleccione el médico del reporte.',
            'id_especialidad.required' => 'Seleccione la especialidad del reporte.',
            'estado.required' => 'Seleccione el estado del reporte.',
            'estado.in' => 'El estado de la cita no es válido.',
            'formato.required' => 'Seleccione el formato de exportación.',
            'formato.in' => 'El formato de exportación no es válido.',
        ];
    }
}
