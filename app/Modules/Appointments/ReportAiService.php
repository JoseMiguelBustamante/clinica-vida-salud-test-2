<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReportAiService
{
    public function isConfigured(): bool
    {
        return $this->apiKey() !== ''
            && $this->model() !== ''
            && $this->baseUrl() !== '';
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     */
    public function answerAboutReport(array $sheet, ?string $question): string
    {
        if (! $this->isConfigured()) {
            throw new ReportAiNotConfigured;
        }

        $payload = $this->compactSheet($sheet);
        $userPrompt = $this->userPrompt($payload, $question);

        return $this->completion($this->systemPrompt(), $userPrompt, 'answerAboutReport');
    }

    /**
     * Interpret a natural-language instruction into report criteria or clarifying questions.
     * Missing required calendar dates must become clarify questions — never invented defaults.
     *
     * @return array{
     *     status: 'ready'|'clarify',
     *     questions: list<string>,
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }
     */
    public function planReportInstruction(string $instruction, string $today): array
    {
        if (! $this->isConfigured()) {
            throw new ReportAiNotConfigured;
        }

        $trimmed = trim($instruction);
        if ($trimmed === '') {
            return $this->clarifyPlan(['Indique qué reporte necesita y el período o fecha a consultar.']);
        }

        $content = $this->completion(
            $this->planningSystemPrompt($today),
            'Instrucción del usuario: '.$trimmed,
            'planReportInstruction',
        );

        $decoded = $this->decodePlanJson($content);
        if ($decoded === null) {
            return $this->clarifyPlan([
                'No pude interpretar la instrucción. Indique el tipo de reporte y las fechas (solo día, sin hora).',
            ]);
        }

        return $this->normalizePlan($decoded);
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'Eres el asistente de reportes administrativos de Clínica Vida Salud.',
            'Responde solo en español, de forma breve y clara.',
            'Usa únicamente los datos del reporte proporcionado.',
            'No inventes citas, totales, pacientes ni médicos.',
            'Si el dato no está en el reporte, dilo explícitamente.',
            'No solicites ni inventes diagnósticos, notas clínicas ni información médica sensible.',
            'No des consejos médicos.',
        ]);
    }

    private function planningSystemPrompt(string $today): string
    {
        $types = [];
        foreach (ReportService::types() as $code => $label) {
            $types[] = $code.' = '.$label;
        }

        return implode(' ', [
            'Eres el planificador del asistente de reportes administrativos de Clínica Vida Salud.',
            'Hoy es '.$today.' (zona de la clínica).',
            'Responde SOLO un JSON válido, sin markdown ni texto extra, con esta forma:',
            '{"status":"ready"|"clarify","questions":[],"tipo":null,"fecha":null,"fecha_inicio":null,"fecha_fin":null,"id_medico":null,"id_especialidad":null,"estado":null,"pregunta":null}.',
            'Tipos permitidos: '.implode('; ', $types).'.',
            'Fechas solo en formato YYYY-MM-DD. No uses hora del reloj.',
            'Si el usuario no dio un parámetro obligatorio del tipo elegido, status=clarify y questions en español pidiendo exactamente lo faltante.',
            'Nunca inventes fechas, médico, especialidad ni estado que el usuario no haya indicado.',
            'Si dijo un período relativo claro (hoy, esta semana, este mes), puedes resolverlo a fechas concretas usando Hoy.',
            'pregunta es la pregunta opcional sobre el reporte ya generado; null si solo quiere generar o resumir.',
            'Para citas_fecha y agenda_diaria usa fecha. Para rangos usa fecha_inicio y fecha_fin (fin = hasta dónde consultar).',
            'estado solo: Programada, Confirmada, Atendida, Cancelada, Inasistencia.',
            'id_medico e id_especialidad solo si el usuario dio un id numérico explícito; si dio un nombre, status=clarify pidiendo el id o que use filtros manuales.',
        ]);
    }

    private function completion(string $system, string $user, string $context): string
    {
        try {
            $response = Http::timeout((int) config('openai.timeout', 30))
                ->withToken($this->apiKey())
                ->acceptJson()
                ->post($this->baseUrl().'/chat/completions', [
                    'model' => $this->model(),
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::error('[ReportAiService - '.$context.'] '.$exception::class);
            throw new ReportAiUnavailable;
        }

        if (! $response->successful()) {
            Log::error('[ReportAiService - '.$context.'] gateway rejected request');
            throw new ReportAiUnavailable;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new ReportAiUnavailable;
        }

        return trim($content);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePlanJson(string $content): ?array
    {
        $json = $content;
        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $json = $matches[0];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *     status: 'ready'|'clarify',
     *     questions: list<string>,
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }
     */
    private function normalizePlan(array $decoded): array
    {
        $questions = [];
        foreach ($decoded['questions'] ?? [] as $question) {
            if (is_string($question) && trim($question) !== '') {
                $questions[] = trim($question);
            }
        }

        $tipo = $this->nullableString($decoded['tipo'] ?? null);
        $allowedTypes = array_keys(ReportService::types());
        if ($tipo !== null && ! in_array($tipo, $allowedTypes, true)) {
            return $this->clarifyPlan(['Indique un tipo de reporte válido de la lista disponible.']);
        }

        $plan = [
            'status' => 'ready',
            'questions' => [],
            'tipo' => $tipo,
            'fecha' => $this->nullableDate($decoded['fecha'] ?? null),
            'fecha_inicio' => $this->nullableDate($decoded['fecha_inicio'] ?? null),
            'fecha_fin' => $this->nullableDate($decoded['fecha_fin'] ?? null),
            'id_medico' => $this->nullablePositiveInt($decoded['id_medico'] ?? null),
            'id_especialidad' => $this->nullablePositiveInt($decoded['id_especialidad'] ?? null),
            'estado' => $this->nullableEstado($decoded['estado'] ?? null),
            'pregunta' => $this->nullableString($decoded['pregunta'] ?? null),
        ];

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        if ($status === 'clarify' || $tipo === null) {
            if ($questions === []) {
                $questions[] = 'Indique el tipo de reporte y las fechas a consultar.';
            }

            return $this->clarifyPlan($questions, $plan);
        }

        $missing = $this->missingRequiredQuestions($plan);
        if ($missing !== []) {
            return $this->clarifyPlan(array_values(array_unique([...$questions, ...$missing])), $plan);
        }

        return $plan;
    }

    /**
     * @param  list<string>  $questions
     * @param  array{
     *     status?: string,
     *     questions?: list<string>,
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }|null  $base
     * @return array{
     *     status: 'clarify',
     *     questions: list<string>,
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }
     */
    private function clarifyPlan(array $questions, ?array $base = null): array
    {
        return [
            'status' => 'clarify',
            'questions' => array_values($questions),
            'tipo' => $base['tipo'] ?? null,
            'fecha' => $base['fecha'] ?? null,
            'fecha_inicio' => $base['fecha_inicio'] ?? null,
            'fecha_fin' => $base['fecha_fin'] ?? null,
            'id_medico' => $base['id_medico'] ?? null,
            'id_especialidad' => $base['id_especialidad'] ?? null,
            'estado' => $base['estado'] ?? null,
            'pregunta' => $base['pregunta'] ?? null,
        ];
    }

    /**
     * @param  array{
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string
     * }  $plan
     * @return list<string>
     */
    private function missingRequiredQuestions(array $plan): array
    {
        return match ($plan['tipo']) {
            ReportService::TYPE_APPOINTMENTS_ON_DATE, ReportService::TYPE_DAILY_AGENDA => $plan['fecha'] === null
                ? ['Indique la fecha del reporte (YYYY-MM-DD).']
                : [],
            ReportService::TYPE_APPOINTMENTS_BY_PHYSICIAN => array_values(array_filter([
                $plan['id_medico'] === null ? 'Indique el id numérico del médico, o use los filtros manuales.' : null,
                $plan['fecha_inicio'] === null ? 'Indique la fecha inicial (YYYY-MM-DD).' : null,
                $plan['fecha_fin'] === null ? 'Indique la fecha final hasta dónde consultar (YYYY-MM-DD).' : null,
            ])),
            ReportService::TYPE_APPOINTMENTS_BY_SPECIALTY => array_values(array_filter([
                $plan['id_especialidad'] === null ? 'Indique el id numérico de la especialidad, o use los filtros manuales.' : null,
                $plan['fecha_inicio'] === null ? 'Indique la fecha inicial (YYYY-MM-DD).' : null,
                $plan['fecha_fin'] === null ? 'Indique la fecha final hasta dónde consultar (YYYY-MM-DD).' : null,
            ])),
            ReportService::TYPE_APPOINTMENTS_BY_STATUS => array_values(array_filter([
                $plan['estado'] === null ? 'Indique el estado (Programada, Confirmada, Atendida, Cancelada o Inasistencia).' : null,
                $plan['fecha_inicio'] === null ? 'Indique la fecha inicial (YYYY-MM-DD).' : null,
                $plan['fecha_fin'] === null ? 'Indique la fecha final hasta dónde consultar (YYYY-MM-DD).' : null,
            ])),
            ReportService::TYPE_PERIOD_TOTALS => array_values(array_filter([
                $plan['fecha_inicio'] === null ? 'Indique la fecha inicial (YYYY-MM-DD).' : null,
                $plan['fecha_fin'] === null ? 'Indique la fecha final hasta dónde consultar (YYYY-MM-DD).' : null,
            ])),
            ReportService::TYPE_REGISTERED_PATIENTS => [],
            default => ['Indique un tipo de reporte válido.'],
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDate(mixed $value): ?string
    {
        $date = $this->nullableString($value);
        if ($date === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }

    private function nullableEstado(mixed $value): ?string
    {
        $estado = $this->nullableString($value);
        if ($estado === null) {
            return null;
        }

        $allowed = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];

        return in_array($estado, $allowed, true) ? $estado : null;
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>, truncated: bool, total_rows: int}  $payload
     */
    private function userPrompt(array $payload, ?string $question): string
    {
        $lines = [
            'Título: '.$payload['title'],
            'Resumen:',
            ...array_map(static fn (string $line): string => '- '.$line, $payload['summary']),
            'Columnas: '.implode(' | ', $payload['headers']),
            'Filas ('.$payload['total_rows'].' en total'
                .($payload['truncated'] ? ', mostrando un subconjunto' : '').'):',
        ];

        foreach ($payload['rows'] as $row) {
            $lines[] = '- '.implode(' | ', $row);
        }

        $trimmed = is_string($question) ? trim($question) : '';
        if ($trimmed === '') {
            $lines[] = 'Tarea: resume el reporte en 3 a 6 viñetas con los hallazgos principales.';
        } else {
            $lines[] = 'Pregunta del usuario: '.$trimmed;
            $lines[] = 'Tarea: responde la pregunta usando solo los datos anteriores.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}  $sheet
     * @return array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>, truncated: bool, total_rows: int}
     */
    private function compactSheet(array $sheet): array
    {
        $maxRows = max(1, (int) config('openai.max_rows', 80));
        $rows = $sheet['rows'];
        $total = count($rows);
        $truncated = $total > $maxRows;

        return [
            'title' => $sheet['title'],
            'headers' => $sheet['headers'],
            'summary' => $sheet['summary'],
            'rows' => $truncated ? array_slice($rows, 0, $maxRows) : $rows,
            'truncated' => $truncated,
            'total_rows' => $total,
        ];
    }

    private function apiKey(): string
    {
        return trim((string) config('openai.api_key', ''));
    }

    private function model(): string
    {
        return trim((string) config('openai.model', ''));
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('openai.base_url', '')), '/');
    }
}
