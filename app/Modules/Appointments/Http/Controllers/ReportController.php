<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Http\Requests\SearchAdministrativeReportsRequest;
use App\Modules\Appointments\InvalidReportInput;
use App\Modules\Appointments\ReportCriterionInvalid;
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
    ) {}

    public function generateAdministrativeReport(SearchAdministrativeReportsRequest $request): View
    {
        $payload = $this->buildReport($request);
        $result = $payload['result'];
        $status = $payload['status'];

        return view('appointments.reports', [
            'types' => ReportService::types(),
            'type' => $result['type'],
            'generated' => $result['generated'] && $status === null,
            'rows' => $result['rows'],
            'groups' => $result['groups'],
            'totals' => $status === null ? $result['totals'] : null,
            'physicians' => $result['physicians'],
            'specialties' => $result['specialties'],
            'fecha' => $result['fecha'],
            'fecha_inicio' => $result['fecha_inicio'],
            'fecha_fin' => $result['fecha_fin'],
            'id_medico' => $result['id_medico'],
            'id_especialidad' => $result['id_especialidad'],
            'estado' => $result['estado'],
            'status' => $status,
            'query' => $this->exportQuery($request),
        ]);
    }

    public function exportAdministrativeReport(SearchAdministrativeReportsRequest $request): StreamedResponse|Response|RedirectResponse
    {
        $validated = $request->validated();
        $format = (string) ($validated['formato'] ?? '');
        $payload = $this->buildReport($request, forceGenerate: true);
        if ($payload['status'] !== null || ! $payload['result']['generated']) {
            return redirect()
                ->route('reports.generate', $this->exportQuery($request))
                ->withErrors(['tipo' => $payload['status'] ?? 'No fue posible exportar el reporte.']);
        }

        $sheet = $this->exports->spreadsheet($payload['result']);
        $basename = 'reporte-'.$payload['result']['type'].'-'.now('UTC')->format('Ymd-His');

        return match ($format) {
            'csv' => $this->exports->toCsvResponse($sheet, $basename),
            'excel' => $this->exports->toExcelResponse($sheet, $basename),
            'pdf' => $this->exports->toPdfResponse($sheet, $basename),
            default => redirect()
                ->route('reports.generate', $this->exportQuery($request))
                ->withErrors(['tipo' => 'El formato de exportación no es válido.']),
        };
    }

    /**
     * @return array{result: array<string, mixed>, status: ?string}
     */
    private function buildReport(SearchAdministrativeReportsRequest $request, bool $forceGenerate = false): array
    {
        $input = $request->validated();
        $generate = $forceGenerate || ($input['generar'] ?? null) === '1';
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
     * @return array<string, string>
     */
    private function exportQuery(SearchAdministrativeReportsRequest $request): array
    {
        $input = $request->validated();
        $query = ['generar' => '1'];
        foreach (['tipo', 'fecha', 'fecha_inicio', 'fecha_fin', 'id_medico', 'id_especialidad', 'estado'] as $key) {
            if (isset($input[$key]) && $input[$key] !== null && $input[$key] !== '') {
                $query[$key] = (string) $input[$key];
            }
        }

        return $query;
    }
}
