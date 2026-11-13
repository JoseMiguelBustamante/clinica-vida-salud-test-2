<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Carbon;
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
    /**
     * @param  array{
     *     tipo?: ?string,
     *     fecha?: ?string,
     *     fecha_inicio?: ?string,
     *     fecha_fin?: ?string,
     *     id_medico?: ?int,
     *     id_especialidad?: ?int,
     *     estado?: ?string
     * }  $priorCriteria
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
    public function planReportInstruction(string $instruction, string $today, array $priorCriteria = []): array
    {
        $trimmed = trim($instruction);
        if ($trimmed === '') {
            return $this->clarifyPlan(['Indique qué reporte necesita y el período (hoy, esta semana, este mes) o una fecha.']);
        }

        $deterministic = $this->deterministicPlan($trimmed, $today);
        if ($deterministic !== null) {
            return $this->normalizePlan(
                $this->applyRelativeDates($deterministic, $trimmed, $today),
                $trimmed,
                $today,
                $priorCriteria,
            );
        }

        if ($this->isDateFocusedInstruction($trimmed, $today) && $this->nullableString($priorCriteria['tipo'] ?? null) !== null) {
            return $this->normalizePlan(
                $this->applyRelativeDates([
                    'status' => 'ready',
                    'questions' => [],
                    'tipo' => null,
                    'fecha' => null,
                    'fecha_inicio' => null,
                    'fecha_fin' => null,
                    'id_medico' => null,
                    'id_especialidad' => null,
                    'estado' => null,
                    'pregunta' => null,
                ], $trimmed, $today),
                $trimmed,
                $today,
                $priorCriteria,
            );
        }

        if (! $this->isConfigured()) {
            throw new ReportAiNotConfigured;
        }

        try {
            $content = $this->completion(
                $this->planningSystemPrompt($today),
                'Instrucción del usuario: '.$trimmed,
                'planReportInstruction',
            );
        } catch (ReportAiUnavailable) {
            return $this->clarifyPlan([
                'No pude contactar al modelo para interpretar la instrucción. Diga el tipo y el período, por ejemplo: Agenda diaria de cada médico para hoy ('.$today.').',
            ]);
        }

        $decoded = $this->decodePlanJson($content);
        if ($decoded === null) {
            return $this->clarifyPlan([
                'No pude interpretar la instrucción. Indique el tipo de reporte y el período (hoy, esta semana, este mes o una fecha).',
            ]);
        }

        return $this->normalizePlan(
            $this->applyRelativeDates($decoded, $trimmed, $today),
            $trimmed,
            $today,
            $priorCriteria,
        );
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
            'Las fechas en el JSON deben ir en YYYY-MM-DD. No uses hora del reloj.',
            'Si el usuario dijo un período relativo, resuélvelo YA a fechas concretas con Hoy='.$today.' y status=ready (no pidas YYYY-MM-DD):',
            'hoy/el día de hoy => fecha='.$today.' o fecha_inicio=fecha_fin='.$today.';',
            'ayer => día anterior a Hoy;',
            'esta semana/la semana => tipo=totales_periodo, fecha_inicio=lunes de esta semana, fecha_fin=Hoy;',
            'este mes/el mes => tipo=totales_periodo, fecha_inicio=primer día del mes, fecha_fin=Hoy;',
            'este año/el año => tipo=totales_periodo, fecha_inicio=1 de enero del año de Hoy, fecha_fin=Hoy;',
            'todas las citas / todo el histórico / histórico => tipo=totales_periodo, fecha_inicio='.ReportDateDefaults::historyStart($today).', fecha_fin=Hoy.',
            'Nunca uses citas_fecha ni agenda_diaria para semana, mes, año o histórico: esos tipos son de un solo día.',
            'Si falta el período, status=clarify y questions en español ofreciendo opciones naturales (hoy, esta semana, este mes, este año, histórico o una fecha), sin exigir el formato YYYY-MM-DD al usuario.',
            'Nunca inventes fechas, médico, especialidad ni estado que el usuario no haya indicado.',
            'pregunta es la pregunta opcional sobre el reporte ya generado; null si solo quiere generar o resumir.',
            'Para citas_fecha y agenda_diaria usa fecha. Para rangos usa fecha_inicio y fecha_fin (fin = hasta dónde consultar).',
            'estado solo: Programada, Confirmada, Atendida, Cancelada, Inasistencia.',
            'id_medico e id_especialidad solo si el usuario dio un id numérico explícito; si dio un nombre, status=clarify pidiendo el id o que use filtros manuales.',
        ]);
    }

    private function completion(string $system, string $user, string $context): string
    {
        try {
            $request = Http::timeout((int) config('openai.timeout', 30))
                ->withToken($this->apiKey())
                ->acceptJson();

            $referer = trim((string) config('openai.http_referer', ''));
            $title = trim((string) config('openai.app_title', ''));
            if ($referer !== '') {
                $request = $request->withHeaders(['HTTP-Referer' => $referer]);
            }
            if ($title !== '') {
                $request = $request->withHeaders(['X-Title' => $title]);
            }

            $response = $request->post($this->baseUrl().'/chat/completions', [
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
            Log::error('[ReportAiService - '.$context.'] gateway rejected request status='.$response->status());
            throw new ReportAiUnavailable;
        }

        $content = $this->messageContent($response->json('choices.0.message.content'));
        if ($content === null) {
            Log::error('[ReportAiService - '.$context.'] empty model content');
            throw new ReportAiUnavailable;
        }

        return $content;
    }

    /**
     * Resolve obvious report requests without calling the model.
     *
     * @return array<string, mixed>|null
     */
    private function deterministicPlan(string $instruction, string $today): ?array
    {
        $normalized = mb_strtolower($instruction);
        $dates = [];
        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $instruction, $matches) > 0) {
            $dates = $matches[1];
        }

        $pregunta = null;
        if (preg_match('/\b(decime|dime|cu[aá]ntas?|cu[aá]ntos?|resum[ií]|pregunt)\b/u', $normalized) === 1) {
            $pregunta = $instruction;
        }

        $base = [
            'status' => 'ready',
            'questions' => [],
            'tipo' => null,
            'fecha' => null,
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'id_medico' => null,
            'id_especialidad' => null,
            'estado' => null,
            'pregunta' => $pregunta,
        ];

        if (str_contains($normalized, 'agenda diaria') || str_contains($normalized, 'agenda de cada m')) {
            $base['tipo'] = ReportService::TYPE_DAILY_AGENDA;
            $base['fecha'] = $dates[0] ?? null;

            return $base;
        }

        if (str_contains($normalized, 'pacientes registrados') || $normalized === 'pacientes') {
            $base['tipo'] = ReportService::TYPE_REGISTERED_PATIENTS;

            return $base;
        }

        if (str_contains($normalized, 'totales') || (str_contains($normalized, 'atendidas') && str_contains($normalized, 'canceladas'))) {
            $base['tipo'] = ReportService::TYPE_PERIOD_TOTALS;
            $base['fecha_inicio'] = $dates[0] ?? null;
            $base['fecha_fin'] = $dates[1] ?? ($dates[0] ?? null);

            return $base;
        }

        if ($this->isFullHistoryInstruction($normalized) || $this->isMultiDayRelativeInstruction($normalized, $today)) {
            $base['tipo'] = ReportService::TYPE_PERIOD_TOTALS;
            if ($dates !== []) {
                $base['fecha_inicio'] = $dates[0] ?? null;
                $base['fecha_fin'] = $dates[1] ?? ($dates[0] ?? null);
            }

            return $base;
        }

        foreach (['programada', 'confirmada', 'atendida', 'cancelada', 'inasistencia'] as $estado) {
            if (str_contains($normalized, 'estado '.$estado) || str_contains($normalized, 'citas en estado '.$estado) || str_contains($normalized, 'citas '.$estado)) {
                $base['tipo'] = ReportService::TYPE_APPOINTMENTS_BY_STATUS;
                $base['estado'] = match ($estado) {
                    'programada' => 'Programada',
                    'confirmada' => 'Confirmada',
                    'atendida' => 'Atendida',
                    'cancelada' => 'Cancelada',
                    'inasistencia' => 'Inasistencia',
                };
                $base['fecha_inicio'] = $dates[0] ?? null;
                $base['fecha_fin'] = $dates[1] ?? ($dates[0] ?? null);

                return $base;
            }
        }

        if (str_contains($normalized, 'citas del ') || str_contains($normalized, 'citas para el ') || str_contains($normalized, 'citas programadas')) {
            $base['tipo'] = ReportService::TYPE_APPOINTMENTS_ON_DATE;
            $base['fecha'] = $dates[0] ?? null;

            return $base;
        }

        if (str_contains($normalized, 'agenda') || str_contains($normalized, 'citas')) {
            if ($this->relativePeriod($normalized, $today) !== null || $dates !== []) {
                $base['tipo'] = str_contains($normalized, 'agenda')
                    ? ReportService::TYPE_DAILY_AGENDA
                    : ReportService::TYPE_APPOINTMENTS_ON_DATE;
                $base['fecha'] = $dates[0] ?? null;

                return $base;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyRelativeDates(array $plan, string $instruction, string $today): array
    {
        $period = $this->relativePeriod(mb_strtolower($instruction), $today);
        if ($period === null) {
            return $plan;
        }

        $tipo = is_string($plan['tipo'] ?? null) ? $plan['tipo'] : null;
        $needsSingle = in_array($tipo, [
            ReportService::TYPE_APPOINTMENTS_ON_DATE,
            ReportService::TYPE_DAILY_AGENDA,
        ], true);
        $needsRange = in_array($tipo, [
            ReportService::TYPE_APPOINTMENTS_BY_PHYSICIAN,
            ReportService::TYPE_APPOINTMENTS_BY_SPECIALTY,
            ReportService::TYPE_APPOINTMENTS_BY_STATUS,
            ReportService::TYPE_PERIOD_TOTALS,
        ], true);

        if ($period['multi_day'] && ($needsSingle || $tipo === null)) {
            $plan['tipo'] = ReportService::TYPE_PERIOD_TOTALS;
            $plan['fecha'] = null;
            $tipo = ReportService::TYPE_PERIOD_TOTALS;
            $needsSingle = false;
            $needsRange = true;
        }

        if ($needsSingle && ($plan['fecha'] ?? null) === null) {
            $plan['fecha'] = $period['single'];
        }

        if ($needsRange) {
            if (($plan['fecha_inicio'] ?? null) === null) {
                $plan['fecha_inicio'] = $period['start'];
            }
            if (($plan['fecha_fin'] ?? null) === null) {
                $plan['fecha_fin'] = $period['end'];
            }
        }

        if ($tipo === null) {
            if (($plan['fecha'] ?? null) === null) {
                $plan['fecha'] = $period['single'];
            }
            if (($plan['fecha_inicio'] ?? null) === null) {
                $plan['fecha_inicio'] = $period['start'];
            }
            if (($plan['fecha_fin'] ?? null) === null) {
                $plan['fecha_fin'] = $period['end'];
            }
        }

        return $plan;
    }

    private function isDateFocusedInstruction(string $instruction, string $today): bool
    {
        $normalized = mb_strtolower(trim($instruction));
        if ($this->isFullHistoryInstruction($normalized) || $this->isMultiDayRelativeInstruction($normalized, $today)) {
            return true;
        }
        if ($this->relativePeriod($normalized, $today) !== null) {
            return preg_match('/\b(agenda|citas|totales|pacientes|medico|médico|especialidad|estado|reporte)\b/u', $normalized) !== 1;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+a\s+\d{4}-\d{2}-\d{2})?$/u', $normalized) === 1;
    }

    private function isFullHistoryInstruction(string $normalizedInstruction): bool
    {
        if (preg_match('/\besta semana\b|\bla semana\b|\beste mes\b|\bel mes\b|\beste a[nñ]o\b|\bel a[nñ]o\b|\ba[nñ]o actual\b|\bmes actual\b|\bsemana actual\b/u', $normalizedInstruction) === 1) {
            return false;
        }

        return preg_match('/\btodas las citas\b|\btodo el hist[oó]rico\b|\bhist[oó]rico\b|\btodo el historial\b|\bdesde siempre\b/u', $normalizedInstruction) === 1;
    }

    private function isMultiDayRelativeInstruction(string $normalizedInstruction, string $today): bool
    {
        $period = $this->relativePeriod($normalizedInstruction, $today);

        return $period !== null && $period['multi_day'];
    }

    /**
     * @return array{single: string, start: string, end: string, multi_day: bool}|null
     */
    private function relativePeriod(string $normalizedInstruction, string $today): ?array
    {
        $day = Carbon::parse($today)->startOfDay();

        if (preg_match('/\bayer\b/u', $normalizedInstruction) === 1) {
            $yesterday = $day->copy()->subDay()->toDateString();

            return ['single' => $yesterday, 'start' => $yesterday, 'end' => $yesterday, 'multi_day' => false];
        }

        if (preg_match('/\bhoy\b|\bel d[ií]a de hoy\b/u', $normalizedInstruction) === 1
            && ! $this->isFullHistoryInstruction($normalizedInstruction)
            && preg_match('/\besta semana\b|\beste mes\b|\beste a[nñ]o\b|\bla semana\b|\bel mes\b|\bel a[nñ]o\b/u', $normalizedInstruction) !== 1) {
            return ['single' => $today, 'start' => $today, 'end' => $today, 'multi_day' => false];
        }

        if (preg_match('/\besta semana\b|\bla semana\b|\bsemana actual\b/u', $normalizedInstruction) === 1) {
            $start = $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            return ['single' => $today, 'start' => $start, 'end' => $today, 'multi_day' => $start !== $today];
        }

        if (preg_match('/\beste mes\b|\bel mes\b|\bmes actual\b/u', $normalizedInstruction) === 1) {
            $start = $day->copy()->startOfMonth()->toDateString();

            return ['single' => $today, 'start' => $start, 'end' => $today, 'multi_day' => $start !== $today];
        }

        if (preg_match('/\beste a[nñ]o\b|\bel a[nñ]o\b|\ba[nñ]o actual\b/u', $normalizedInstruction) === 1) {
            $start = $day->copy()->startOfYear()->toDateString();

            return ['single' => $today, 'start' => $start, 'end' => $today, 'multi_day' => $start !== $today];
        }

        if ($this->isFullHistoryInstruction($normalizedInstruction)) {
            $start = ReportDateDefaults::historyStart($today);

            return ['single' => $today, 'start' => $start, 'end' => $today, 'multi_day' => true];
        }

        return null;
    }

    private function messageContent(mixed $content): ?string
    {
        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                $parts[] = trim($part['text']);
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode("\n", $parts);
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
     * @param  array{
     *     tipo?: ?string,
     *     fecha?: ?string,
     *     fecha_inicio?: ?string,
     *     fecha_fin?: ?string,
     *     id_medico?: ?int,
     *     id_especialidad?: ?int,
     *     estado?: ?string
     * }  $priorCriteria
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
    private function normalizePlan(array $decoded, string $instruction = '', string $today = '', array $priorCriteria = []): array
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

        $plan = $this->mergeDateFocusedFollowUp($plan, $instruction, $today, $priorCriteria);

        if ($plan['tipo'] === null) {
            if ($questions === []) {
                $questions[] = 'Indique el tipo de reporte y el período a consultar (hoy, esta semana, este mes, este año, histórico o una fecha).';
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
     * @param  array{
     *     status: string,
     *     questions: list<string>,
     *     tipo: ?string,
     *     fecha: ?string,
     *     fecha_inicio: ?string,
     *     fecha_fin: ?string,
     *     id_medico: ?int,
     *     id_especialidad: ?int,
     *     estado: ?string,
     *     pregunta: ?string
     * }  $plan
     * @param  array{
     *     tipo?: ?string,
     *     fecha?: ?string,
     *     fecha_inicio?: ?string,
     *     fecha_fin?: ?string,
     *     id_medico?: ?int,
     *     id_especialidad?: ?int,
     *     estado?: ?string
     * }  $priorCriteria
     * @return array{
     *     status: string,
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
    private function mergeDateFocusedFollowUp(array $plan, string $instruction, string $today, array $priorCriteria): array
    {
        $priorTipo = $this->nullableString($priorCriteria['tipo'] ?? null);
        if ($priorTipo === null || $plan['tipo'] !== null || $instruction === '' || $today === '') {
            return $plan;
        }

        $normalized = mb_strtolower($instruction);
        $hasExplicitDate = preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $instruction) === 1;
        $hasRelative = $this->relativePeriod($normalized, $today) !== null;
        if (! $hasExplicitDate && ! $hasRelative) {
            return $plan;
        }

        $period = $hasRelative ? $this->relativePeriod($normalized, $today) : null;
        $priorIsSingleDay = in_array($priorTipo, [
            ReportService::TYPE_APPOINTMENTS_ON_DATE,
            ReportService::TYPE_DAILY_AGENDA,
        ], true);
        if ($period !== null && $period['multi_day'] && $priorIsSingleDay) {
            $plan['tipo'] = ReportService::TYPE_PERIOD_TOTALS;
            $plan['fecha'] = null;
        } else {
            $plan['tipo'] = $priorTipo;
            $plan['id_medico'] = $plan['id_medico'] ?? $this->nullablePositiveInt($priorCriteria['id_medico'] ?? null);
            $plan['id_especialidad'] = $plan['id_especialidad'] ?? $this->nullablePositiveInt($priorCriteria['id_especialidad'] ?? null);
            $plan['estado'] = $plan['estado'] ?? $this->nullableEstado($priorCriteria['estado'] ?? null);
        }

        if ($hasExplicitDate && preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $instruction, $matches) > 0) {
            $dates = $matches[1];
            $effectiveTipo = is_string($plan['tipo'] ?? null) ? $plan['tipo'] : $priorTipo;
            if (in_array($effectiveTipo, [ReportService::TYPE_APPOINTMENTS_ON_DATE, ReportService::TYPE_DAILY_AGENDA], true)) {
                $plan['fecha'] = $plan['fecha'] ?? ($dates[0] ?? null);
            } else {
                $plan['fecha_inicio'] = $plan['fecha_inicio'] ?? ($dates[0] ?? null);
                $plan['fecha_fin'] = $plan['fecha_fin'] ?? ($dates[1] ?? ($dates[0] ?? null));
            }
        }

        return $this->applyRelativeDates($plan, $instruction, $today);
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
                ? ['Indique el día del reporte: diga hoy, ayer o una fecha.']
                : [],
            ReportService::TYPE_APPOINTMENTS_BY_PHYSICIAN => array_values(array_filter([
                $plan['id_medico'] === null ? 'Indique el id numérico del médico, o use los filtros manuales.' : null,
                $plan['fecha_inicio'] === null || $plan['fecha_fin'] === null
                    ? 'Indique el período: hoy, esta semana, este mes o fechas concretas.'
                    : null,
            ])),
            ReportService::TYPE_APPOINTMENTS_BY_SPECIALTY => array_values(array_filter([
                $plan['id_especialidad'] === null ? 'Indique el id numérico de la especialidad, o use los filtros manuales.' : null,
                $plan['fecha_inicio'] === null || $plan['fecha_fin'] === null
                    ? 'Indique el período: hoy, esta semana, este mes o fechas concretas.'
                    : null,
            ])),
            ReportService::TYPE_APPOINTMENTS_BY_STATUS => array_values(array_filter([
                $plan['estado'] === null ? 'Indique el estado (Programada, Confirmada, Atendida, Cancelada o Inasistencia).' : null,
                $plan['fecha_inicio'] === null || $plan['fecha_fin'] === null
                    ? 'Indique el período: hoy, esta semana, este mes o fechas concretas.'
                    : null,
            ])),
            ReportService::TYPE_PERIOD_TOTALS => array_values(array_filter([
                $plan['fecha_inicio'] === null || $plan['fecha_fin'] === null
                    ? 'Indique el período: hoy, esta semana, este mes o fechas concretas.'
                    : null,
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
