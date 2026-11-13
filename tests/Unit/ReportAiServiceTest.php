<?php

namespace Tests\Unit;

use App\Modules\Appointments\ReportAiNotConfigured;
use App\Modules\Appointments\ReportAiService;
use App\Modules\Appointments\ReportAiUnavailable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportAiServiceTest extends TestCase
{
    public function test_missing_env_settings_are_not_configured(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);

        $this->assertFalse((new ReportAiService)->isConfigured());
        $this->expectException(ReportAiNotConfigured::class);
        (new ReportAiService)->answerAboutReport($this->sheet(), null);
    }

    public function test_answer_uses_only_the_provided_sheet(): void
    {
        config([
            'openai.api_key' => 'test-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Resumen breve']]],
            ], 200),
        ]);

        $answer = (new ReportAiService)->answerAboutReport($this->sheet(), '¿Cuántas hay?');

        $this->assertSame('Resumen breve', $answer);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $user = (string) ($payload['messages'][1]['content'] ?? '');

            return ($payload['model'] ?? null) === 'gpt-4o-mini'
                && str_contains($user, 'CIT-9')
                && str_contains($user, '¿Cuántas hay?')
                && ! str_contains($user, 'diagnóstico');
        });
    }

    public function test_gateway_failure_is_unavailable(): void
    {
        config([
            'openai.api_key' => 'test-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'no'], 500),
        ]);

        $this->expectException(ReportAiUnavailable::class);
        (new ReportAiService)->answerAboutReport($this->sheet(), null);
    }

    public function test_plan_detects_daily_agenda_without_calling_the_provider(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        Http::fake();

        $plan = (new ReportAiService)->planReportInstruction(
            'Agenda diaria de cada médico para el 2026-11-12',
            '2026-11-12',
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('agenda_diaria', $plan['tipo']);
        $this->assertSame('2026-11-12', $plan['fecha']);
        Http::assertNothingSent();
    }

    public function test_plan_asks_when_required_dates_are_missing(): void
    {
        config([
            'openai.api_key' => 'test-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'status' => 'ready',
                            'tipo' => 'totales_periodo',
                            'fecha_inicio' => null,
                            'fecha_fin' => null,
                            'pregunta' => null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $plan = (new ReportAiService)->planReportInstruction(
            'Quiero el reporte de atendidas y canceladas',
            '2026-11-12',
        );

        $this->assertSame('clarify', $plan['status']);
        $this->assertNotEmpty($plan['questions']);
        $this->assertTrue(collect($plan['questions'])->contains(
            fn (string $question): bool => str_contains(mb_strtolower($question), 'fecha')
                || str_contains(mb_strtolower($question), 'período')
                || str_contains(mb_strtolower($question), 'periodo')
        ));
    }

    public function test_plan_returns_ready_criteria_from_instruction(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        Http::fake();

        $plan = (new ReportAiService)->planReportInstruction(
            'Armá citas del 2026-11-10 y decime cuántas canceladas hubo',
            '2026-11-12',
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('citas_fecha', $plan['tipo']);
        $this->assertSame('2026-11-10', $plan['fecha']);
        $this->assertSame('Armá citas del 2026-11-10 y decime cuántas canceladas hubo', $plan['pregunta']);
        $this->assertSame([], $plan['questions']);
        Http::assertNothingSent();
    }

    public function test_plan_resolves_relative_week_for_period_totals_without_provider(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        Http::fake();

        $plan = (new ReportAiService)->planReportInstruction(
            'Quiero totales de atendidas y canceladas de esta semana',
            '2026-11-13',
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('totales_periodo', $plan['tipo']);
        $this->assertSame('2026-11-09', $plan['fecha_inicio']);
        $this->assertSame('2026-11-13', $plan['fecha_fin']);
        Http::assertNothingSent();
    }

    public function test_plan_resolves_hoy_for_daily_agenda_without_provider(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        Http::fake();

        $plan = (new ReportAiService)->planReportInstruction(
            'Agenda diaria de cada médico para hoy',
            '2026-11-13',
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('agenda_diaria', $plan['tipo']);
        $this->assertSame('2026-11-13', $plan['fecha']);
        Http::assertNothingSent();
    }

    public function test_plan_merges_date_only_follow_up_with_prior_tipo(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        Http::fake();

        $plan = (new ReportAiService)->planReportInstruction(
            'hoy',
            '2026-11-13',
            ['tipo' => 'agenda_diaria'],
        );

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('agenda_diaria', $plan['tipo']);
        $this->assertSame('2026-11-13', $plan['fecha']);
        Http::assertNothingSent();
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<string>>, summary: list<string>}
     */
    private function sheet(): array
    {
        return [
            'title' => 'Citas programadas para una fecha',
            'headers' => ['Código', 'Estado'],
            'rows' => [['CIT-9', 'Programada']],
            'summary' => ['Total de citas: 1'],
        ];
    }
}
