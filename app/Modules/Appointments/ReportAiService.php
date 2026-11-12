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

        try {
            $response = Http::timeout((int) config('openai.timeout', 30))
                ->withToken($this->apiKey())
                ->acceptJson()
                ->post($this->baseUrl().'/chat/completions', [
                    'model' => $this->model(),
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::error('[ReportAiService - answerAboutReport] '.$exception::class);
            throw new ReportAiUnavailable;
        }

        if (! $response->successful()) {
            Log::error('[ReportAiService - answerAboutReport] gateway rejected request');
            throw new ReportAiUnavailable;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new ReportAiUnavailable;
        }

        return trim($content);
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
