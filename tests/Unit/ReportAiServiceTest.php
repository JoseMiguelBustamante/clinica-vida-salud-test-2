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
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'no'], 500),
        ]);

        $this->expectException(ReportAiUnavailable::class);
        (new ReportAiService)->answerAboutReport($this->sheet(), null);
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
