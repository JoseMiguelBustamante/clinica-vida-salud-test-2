<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Http\Requests\AskReportAssistantRequest;
use App\Modules\Appointments\Http\Requests\SearchAdministrativeReportsRequest;
use App\Modules\Appointments\InvalidReportInput;
use App\Modules\Appointments\ReportAiNotConfigured;
use App\Modules\Appointments\ReportAiService;
use App\Modules\Appointments\ReportAiUnavailable;
use App\Modules\Appointments\ReportCriterionInvalid;
use App\Modules\Appointments\ReportDateDefaults;
use App\Modules\Appointments\ReportExportService;
use App\Modules\Appointments\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ReportController
{
    public function __construct(
        private ReportService $reports,
        private ReportExportService $exports,
        private ReportAiService $assistant,
    ) {}

    public function generateAdministrativeReport(SearchAdministrativeReportsRequest $request): View
    {
        $payload = $this->buildReport($request->validated());
        $result = $payload['result'];
        $status = $payload['status'];

        $defaults = ReportDateDefaults::apply([
            'tipo' => $result['type'],
            'fecha' => $result['fecha'],
            'fecha_inicio' => $result['fecha_inicio'],
            'fecha_fin' => $result['fecha_fin'],
        ]);

        return view('appointments.reports', [
            'types' => ReportService::types(),
            'type' => $result['type'],
            'generated' => $result['generated'] && $status === null,
            'rows' => $result['rows'],
            'groups' => $result['groups'],
            'totals' => $status === null ? $result['totals'] : null,
            'physicians' => $result['physicians'],
            'specialties' => $result['specialties'],
            'fecha' => (string) ($defaults['fecha'] ?? ''),
            'fecha_inicio' => (string) ($defaults['fecha_inicio'] ?? ''),
            'fecha_fin' => (string) ($defaults['fecha_fin'] ?? ''),
            'id_medico' => $result['id_medico'],
            'id_especialidad' => $result['id_especialidad'],
            'estado' => $result['estado'],
            'status' => $status,
            'query' => $this->queryFromInput($request->validated()),
            'presetToday' => ReportDateDefaults::today(),
            'presetWeekStart' => ReportDateDefaults::weekStart(),
            'presetMonthStart' => ReportDateDefaults::monthStart(),
            'aiConfigured' => $this->assistant->isConfigured(),
            'aiAnswer' => session('ai_answer'),
            'aiQuestion' => session('ai_question'),
            'aiClarifications' => session('ai_clarifications', []),
        ]);
    }

    public function exportAdministrativeReport(SearchAdministrativeReportsRequest $request): StreamedResponse|Response|RedirectResponse
    {
        $validated = $request->validated();
        $format = (string) ($validated['formato'] ?? '');
        $payload = $this->buildReport($validated, forceGenerate: true);
        if ($payload['status'] !== null || ! $payload['result']['generated']) {
            return redirect()
                ->route('reports.generate', $this->queryFromInput($validated))
                ->withErrors(['tipo' => $payload['status'] ?? 'No fue posible exportar el reporte.']);
        }

        $sheet = $this->exports->spreadsheet($payload['result']);
        $basename = 'reporte-'.$payload['result']['type'].'-'.now('UTC')->format('Ymd-His');

        return match ($format) {
            'csv' => $this->exports->toCsvResponse($sheet, $basename),
            'excel' => $this->exports->toExcelResponse($sheet, $basename),
            'pdf' => $this->exports->toPdfResponse($sheet, $basename),
            default => redirect()
                ->route('reports.generate', $this->queryFromInput($validated))
                ->withErrors(['tipo' => 'El formato de exportación no es válido.']),
        };
    }

    public function askReportAssistant(AskReportAssistantRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $action = (string) $validated['accion'];
        $instruction = trim((string) ($validated['pregunta'] ?? ''));
        $query = $this->assistantQueryFromInput($validated);
        $question = null;
        $answer = null;

        try {
            if (! $this->isReadyToAutoGenerate($validated)) {
                $plan = $this->assistant->planReportInstruction(
                    $instruction !== '' ? $instruction : 'Necesito ayuda para generar un reporte.',
                    ReportDateDefaults::today(),
                );

                if ($plan['status'] === 'clarify') {
                    return redirect()
                        ->route('reports.generate', $query)
                        ->with('ai_clarifications', $plan['questions'])
                        ->with('ai_question', $instruction !== '' ? $instruction : null);
                }

                $validated = $this->mergePlanIntoInput($validated, $plan);
                $query = $this->queryFromInput($validated);
                if ($action === 'preguntar' && $instruction === '' && is_string($plan['pregunta'] ?? null)) {
                    $instruction = trim((string) $plan['pregunta']);
                }
            } else {
                $query = $this->queryFromInput($validated);
            }

            if (! $this->isReadyToAutoGenerate($validated)) {
                return redirect()
                    ->route('reports.generate', $this->assistantQueryFromInput($validated))
                    ->with('ai_clarifications', ['Faltan datos para generar el reporte. Indique tipo y fechas (solo día).'])
                    ->with('ai_question', $instruction !== '' ? $instruction : null);
            }

            $payload = $this->buildReport($validated, forceGenerate: true);
            if ($payload['status'] !== null || ! $payload['result']['generated']) {
                return redirect()
                    ->route('reports.generate', $query)
                    ->withErrors(['tipo' => $payload['status'] ?? 'No fue posible preparar el reporte para el asistente.']);
            }

            $sheet = $this->exports->spreadsheet($payload['result']);
            $question = $action === 'preguntar' ? $instruction : null;
            $answer = $this->assistant->answerAboutReport($sheet, $question);
        } catch (ReportAiNotConfigured) {
            return redirect()
                ->route('reports.generate', $query)
                ->withErrors(['pregunta' => 'El asistente no está configurado. Defina OPENAI_API_KEY, OPENAI_BASE_URL y OPENAI_MODEL en Dokploy.']);
        } catch (ReportAiUnavailable) {
            return redirect()
                ->route('reports.generate', $query)
                ->withErrors(['pregunta' => 'No fue posible obtener una respuesta del asistente.']);
        } catch (Throwable $exception) {
            Log::error('[ReportController - askReportAssistant] '.$exception::class);

            return redirect()
                ->route('reports.generate', $query)
                ->withErrors(['pregunta' => 'No fue posible obtener una respuesta del asistente.']);
        }

        return redirect()
            ->route('reports.generate', $query)
            ->with('ai_answer', $answer)
            ->with('ai_question', $question ?? 'Resumen del reporte');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, status: ?string}
     */
    private function buildReport(array $input, bool $forceGenerate = false): array
    {
        $input = ReportDateDefaults::apply($input);
        $generate = $forceGenerate
            || ($input['generar'] ?? null) === '1'
            || $this->isReadyToAutoGenerate($input);
        $status = null;
        $result = [
            'type' => $input['tipo'] ?? '',
            'generated' => false,
            'rows' => null,
            'groups' => null,
            'totals' => null,
            'physicians' => collect(),
            'specialties' => collect(),
            'fecha' => $input['fecha'] ?? '',
            'fecha_inicio' => $input['fecha_inicio'] ?? '',
            'fecha_fin' => $input['fecha_fin'] ?? '',
            'id_medico' => isset($input['id_medico']) ? (int) $input['id_medico'] : null,
            'id_especialidad' => isset($input['id_especialidad']) ? (int) $input['id_especialidad'] : null,
            'estado' => $input['estado'] ?? '',
        ];
        try {
            $result = $this->reports->generateAdministrativeReport([
                'tipo' => $input['tipo'] ?? null,
                'fecha' => $input['fecha'] ?? null,
                'fecha_inicio' => $input['fecha_inicio'] ?? null,
                'fecha_fin' => $input['fecha_fin'] ?? null,
                'id_medico' => isset($input['id_medico']) ? (int) $input['id_medico'] : null,
                'id_especialidad' => isset($input['id_especialidad']) ? (int) $input['id_especialidad'] : null,
                'estado' => $input['estado'] ?? null,
            ], $generate);
        } catch (InvalidReportInput) {
            $status = 'Faltan filtros obligatorios o los valores ingresados no son válidos.';
        } catch (ReportCriterionInvalid) {
            $status = 'El médico, la especialidad o el estado seleccionado no es válido para este reporte.';
        } catch (Throwable $exception) {
            Log::error('[ReportController - buildReport] '.$exception::class);
            $status = 'No fue posible generar el reporte.';
        }

        return ['result' => $result, 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function queryFromInput(array $input): array
    {
        $input = ReportDateDefaults::apply($input);
        $query = ['generar' => '1'];
        foreach (['tipo', 'fecha', 'fecha_inicio', 'fecha_fin', 'id_medico', 'id_especialidad', 'estado'] as $key) {
            if (isset($input[$key]) && $input[$key] !== null && $input[$key] !== '') {
                $query[$key] = (string) $input[$key];
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function assistantQueryFromInput(array $input): array
    {
        $query = [];
        foreach (['tipo', 'fecha', 'fecha_inicio', 'fecha_fin', 'id_medico', 'id_especialidad', 'estado'] as $key) {
            if (isset($input[$key]) && $input[$key] !== null && $input[$key] !== '') {
                $query[$key] = (string) $input[$key];
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }  $plan
     * @return array<string, mixed>
     */
    private function mergePlanIntoInput(array $input, array $plan): array
    {
        foreach (['tipo', 'fecha', 'fecha_inicio', 'fecha_fin', 'estado'] as $key) {
            if (($plan[$key] ?? null) !== null && ($plan[$key] ?? '') !== '') {
                $input[$key] = $plan[$key];
            }
        }

        foreach (['id_medico', 'id_especialidad'] as $key) {
            if (($plan[$key] ?? null) !== null) {
                $input[$key] = $plan[$key];
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isReadyToAutoGenerate(array $input): bool
    {
        $type = (string) ($input['tipo'] ?? '');

        return match ($type) {
            ReportService::TYPE_APPOINTMENTS_ON_DATE, ReportService::TYPE_DAILY_AGENDA => $this->filled($input['fecha'] ?? null),
            ReportService::TYPE_PERIOD_TOTALS => $this->filled($input['fecha_inicio'] ?? null) && $this->filled($input['fecha_fin'] ?? null),
            ReportService::TYPE_REGISTERED_PATIENTS => true,
            ReportService::TYPE_APPOINTMENTS_BY_PHYSICIAN => $this->filled($input['id_medico'] ?? null)
                && $this->filled($input['fecha_inicio'] ?? null)
                && $this->filled($input['fecha_fin'] ?? null),
            ReportService::TYPE_APPOINTMENTS_BY_SPECIALTY => $this->filled($input['id_especialidad'] ?? null)
                && $this->filled($input['fecha_inicio'] ?? null)
                && $this->filled($input['fecha_fin'] ?? null),
            ReportService::TYPE_APPOINTMENTS_BY_STATUS => $this->filled($input['estado'] ?? null)
                && $this->filled($input['fecha_inicio'] ?? null)
                && $this->filled($input['fecha_fin'] ?? null),
            default => false,
        };
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
