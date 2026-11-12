<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AskReportAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = 'citas_fecha,citas_medico,citas_especialidad,citas_estado,pacientes,totales_periodo,agenda_diaria';
        $action = (string) $this->input('accion', 'resumir');
        $type = trim((string) $this->input('tipo', ''));
        $hasType = $type !== '';

        $rules = [
            'tipo' => ['nullable', 'in:'.$types],
            'accion' => ['required', 'in:resumir,preguntar'],
            'pregunta' => [
                $hasType && $action === 'resumir' ? 'nullable' : 'required',
                'string',
                'max:500',
            ],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            'id_medico' => ['nullable', 'integer'],
            'id_especialidad' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:Programada,Confirmada,Atendida,Cancelada,Inasistencia'],
        ];

        if (! $hasType) {
            return $rules;
        }

        return match ($type) {
            'citas_fecha', 'agenda_diaria' => array_merge($rules, [
                'fecha' => ['nullable', 'date_format:Y-m-d'],
            ]),
            'citas_medico' => array_merge($rules, [
                'id_medico' => ['nullable', 'integer'],
                'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
                'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            ]),
            'citas_especialidad' => array_merge($rules, [
                'id_especialidad' => ['nullable', 'integer'],
                'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
                'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            ]),
            'citas_estado' => array_merge($rules, [
                'estado' => ['nullable', 'in:Programada,Confirmada,Atendida,Cancelada,Inasistencia'],
                'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
                'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            ]),
            'totales_periodo' => array_merge($rules, [
                'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
                'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
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
            'tipo.in' => 'El tipo de reporte no es válido.',
            'accion.required' => 'Indique la acción del asistente.',
            'accion.in' => 'La acción del asistente no es válida.',
            'pregunta.required' => 'Escriba una instrucción o pregunta para el asistente.',
            'pregunta.max' => 'La pregunta es demasiado larga.',
            'fecha.date_format' => 'Indique una fecha válida.',
            'fecha_inicio.date_format' => 'Indique una fecha inicial válida.',
            'fecha_fin.date_format' => 'Indique una fecha final válida.',
        ];
    }
}
