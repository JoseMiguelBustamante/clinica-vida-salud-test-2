<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportService
{
    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, fecha: string, fecha_inicio: string, fecha_fin: string, estado: string}  $result
     * @return array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}
     */
    public function spreadsheet(array $result): array
    {
        $title = ReportService::types()[$result['type']] ?? 'Reporte';
        $summary = $this->summaryLines($result);
        [$headers, $rows] = $this->table($result);

        return [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     */
    public function toCsvResponse(array $sheet, string $basename): StreamedResponse
    {
        return response()->streamDownload(function () use ($sheet): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$sheet['title']]);
            foreach ($sheet['summary'] as $line) {
                fputcsv($out, [$line]);
            }
            fputcsv($out, []);
            fputcsv($out, $sheet['headers']);
            foreach ($sheet['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $basename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     */
    public function toExcelResponse(array $sheet, string $basename): StreamedResponse
    {
        $xml = $this->spreadsheetXml($sheet);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $basename.'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     */
    public function toPdfResponse(array $sheet, string $basename): \Illuminate\Http\Response
    {
        $pdf = new SimpleReportPdf;
        $binary = $pdf->render($sheet['title'], $sheet['summary'], $sheet['headers'], $sheet['rows']);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$basename.'.pdf"',
        ]);
    }

    /**
     * @param  array{type: string, rows: ?Collection, groups: ?Collection, totals: ?array, fecha: string, fecha_inicio: string, fecha_fin: string, estado: string}  $result
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function table(array $result): array
    {
        return match ($result['type']) {
            ReportService::TYPE_REGISTERED_PATIENTS => $this->patientTable($result['rows'] ?? collect()),
            ReportService::TYPE_PERIOD_TOTALS => $this->periodTotalsTable($result['totals'] ?? []),
            ReportService::TYPE_DAILY_AGENDA => $this->agendaTable($result['groups'] ?? collect()),
            default => $this->appointmentTable($result['rows'] ?? collect()),
        };
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function appointmentTable(Collection $rows): array
    {
        $headers = ['Fecha', 'Horario', 'Código', 'Paciente', 'Médico', 'Especialidad', 'Estado'];
        $body = $rows->map(function (object $appointment): array {
            $start = Carbon::parse((string) $appointment->inicio)->timezone('UTC');
            $end = Carbon::parse((string) $appointment->fin)->timezone('UTC');

            return [
                $start->format('Y-m-d'),
                $start->format('H:i').' - '.$end->format('H:i').' UTC',
                (string) $appointment->codigo,
                trim(($appointment->paciente_apellidos ?? '').', '.($appointment->paciente_nombres ?? ''), ' ,'),
                trim(($appointment->medico_apellidos ?? '').', '.($appointment->medico_nombres ?? ''), ' ,'),
                (string) ($appointment->especialidad_nombre ?? ''),
                (string) $appointment->estado,
            ];
        })->all();

        return [$headers, $body];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function patientTable(Collection $rows): array
    {
        $headers = ['CI', 'Nombres', 'Apellidos', 'Teléfono', 'Correo', 'Estado'];
        $body = $rows->map(fn (object $patient): array => [
            (string) ($patient->ci ?: '—'),
            (string) $patient->nombres,
            (string) $patient->apellidos,
            (string) ($patient->telefono ?: '—'),
            (string) ($patient->correo_contacto ?: '—'),
            ((bool) $patient->activo) ? 'Activo' : 'Inactivo',
        ])->all();

        return [$headers, $body];
    }

    /**
     * @param  array<string, int>  $totals
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function periodTotalsTable(array $totals): array
    {
        return [
            ['Estado', 'Cantidad'],
            [
                ['Atendida', (string) ($totals['Atendida'] ?? 0)],
                ['Cancelada', (string) ($totals['Cancelada'] ?? 0)],
                ['Inasistencia', (string) ($totals['Inasistencia'] ?? 0)],
            ],
        ];
    }

    /**
     * @param  Collection<int, object>  $groups
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function agendaTable(Collection $groups): array
    {
        $headers = ['Médico', 'Horario', 'Código', 'Paciente', 'Especialidad', 'Estado'];
        $body = [];
        foreach ($groups as $group) {
            $physician = $group->physician;
            $name = trim(($physician->apellidos ?? '').', '.($physician->nombres ?? ''), ' ,');
            foreach ($group->appointments as $appointment) {
                $start = Carbon::parse((string) $appointment->inicio)->timezone('UTC');
                $end = Carbon::parse((string) $appointment->fin)->timezone('UTC');
                $body[] = [
                    $name,
                    $start->format('H:i').' - '.$end->format('H:i').' UTC',
                    (string) $appointment->codigo,
                    trim(($appointment->paciente_apellidos ?? '').', '.($appointment->paciente_nombres ?? ''), ' ,'),
                    (string) ($appointment->especialidad_nombre ?? ''),
                    (string) $appointment->estado,
                ];
            }
        }

        return [$headers, $body];
    }

    /**
     * @param  array{type: string, totals: ?array, fecha: string, fecha_inicio: string, fecha_fin: string, estado: string}  $result
     * @return list<string>
     */
    private function summaryLines(array $result): array
    {
        $lines = ['Clínica Vida Salud · Reportes administrativos'];
        $lines[] = 'Tipo: '.(ReportService::types()[$result['type']] ?? $result['type']);
        if ($result['fecha'] !== '') {
            $lines[] = 'Fecha: '.$result['fecha'];
        }
        if ($result['fecha_inicio'] !== '' && $result['fecha_fin'] !== '') {
            $lines[] = 'Período: '.$result['fecha_inicio'].' a '.$result['fecha_fin'];
        }
        if ($result['estado'] !== '') {
            $lines[] = 'Estado: '.$result['estado'];
        }
        $totals = $result['totals'] ?? [];
        if (isset($totals['citas'])) {
            $lines[] = 'Total de citas: '.$totals['citas'];
        }
        if (isset($totals['Confirmada'])) {
            $lines[] = 'Confirmadas: '.$totals['Confirmada'].' · Pendientes: '.($totals['Pendientes'] ?? 0).' · Canceladas: '.($totals['Cancelada'] ?? 0);
        }
        if (isset($totals['pacientes'])) {
            $lines[] = 'Pacientes: '.$totals['pacientes'].' · Activos: '.$totals['activos'].' · Inactivos: '.$totals['inactivos'];
        }
        if (isset($totals['Atendida']) && ! isset($totals['citas'])) {
            $lines[] = 'Atendidas: '.$totals['Atendida'].' · Canceladas: '.$totals['Cancelada'].' · Inasistencias: '.$totals['Inasistencia'];
        }

        return $lines;
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     */
    private function spreadsheetXml(array $sheet): string
    {
        $cell = static function (string $value): string {
            $safe = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return '<Cell><Data ss:Type="String">'.$safe.'</Data></Cell>';
        };

        $rowsXml = '';
        $rowsXml .= '<Row>'.$cell($sheet['title']).'</Row>';
        foreach ($sheet['summary'] as $line) {
            $rowsXml .= '<Row>'.$cell($line).'</Row>';
        }
        $rowsXml .= '<Row></Row><Row>';
        foreach ($sheet['headers'] as $header) {
            $rowsXml .= $cell($header);
        }
        $rowsXml .= '</Row>';
        foreach ($sheet['rows'] as $row) {
            $rowsXml .= '<Row>';
            foreach ($row as $value) {
                $rowsXml .= $cell((string) $value);
            }
            $rowsXml .= '</Row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            .'<Worksheet ss:Name="Reporte"><Table>'.$rowsXml.'</Table></Worksheet></Workbook>';
    }
}
