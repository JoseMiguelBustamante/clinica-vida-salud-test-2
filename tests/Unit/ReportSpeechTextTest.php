<?php

namespace Tests\Unit;

use App\Modules\Appointments\ReportSpeechText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportSpeechTextTest extends TestCase
{
    #[Test]
    public function test_plain_strips_markdown_markers_and_emoji(): void
    {
        $raw = "Hola 👋 Soy el asistente.\n\nSegún el reporte: **Citas programadas para el 2026-11-13**, con un total de **0 citas**.";

        $plain = ReportSpeechText::plain($raw);

        $this->assertSame(
            "Hola Soy el asistente.\n\nSegún el reporte: Citas programadas para el 2026-11-13, con un total de 0 citas.",
            $plain,
        );
        $this->assertStringNotContainsString('**', $plain);
        $this->assertStringNotContainsString('👋', $plain);
    }
}
