<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\MedicalStaff\SpecialtyRepository;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_reports(): void
    {
        $this->get('/reportes')->assertRedirect(route('login'));
        $this->get('/reports')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_report_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_HISTORY,
            PermissionCatalog::CONSULT_AGENDA,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_report_form_opens_without_listing_results(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->never();
        $appointments->shouldReceive('getAppointmentStatusCountsInRange')->never();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes')->assertOk()->assertSee('Reportes administrativos')
            ->assertSee('Asistente del reporte')
            ->assertSee('Citas programadas para una fecha')
            ->assertSee('Generar reporte')
            ->assertSee('Escriba arriba qué reporte necesita')
            ->assertDontSee('Criterios aplicados');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_mobile_navigation_and_assistant_are_discoverable_before_generation(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.model' => 'test-model', 'openai.base_url' => 'https://provider.invalid/v1']);
        $this->authorizeReports(1, true);
        $this->mockCatalogLookups();
        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->get('/reportes?tipo=citas_medico');
        $response->assertOk()->assertSee('name="fecha_inicio"', false)->assertSee('name="fecha_fin"', false);
        $this->saveBrowserFixture('initial', $response->getContent());
        $response->assertSee('id="app-navigation"', false)
            ->assertSee('Menú de navegación')
            ->assertSee('id="asistente-reporte"', false)
            ->assertSee('id="pregunta"', false)
            ->assertSee('id="reports-dictate"', false)
            ->assertSee('reports-listen-btn', false)
            ->assertSee('Hablar con el asistente')
            ->assertSee('Escribir en texto')
            ->assertSee('data-reports-voice', false)
            ->assertSee('data-voice-mode', false)
            ->assertSee('reportsVoiceInteractive', false)
            ->assertSee('isSecureContext', false)
            ->assertSee('getUserMedia', false)
            ->assertSee('submitVoiceTurn', false)
            ->assertSee('speakableText', false)
            ->assertSee('Filtros manuales')
            ->assertSee('data-reports-date-presets', false)
            ->assertSee('data-reports-preset', false)
            ->assertSee('hoy, esta semana, este mes', false)
            ->assertDontSee('Ir al asistente IA')
            ->assertDontSee('Primero genere un reporte para habilitar las preguntas.');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//textarea[@id="pregunta" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@class,"reports-ai-form")]//button[@name="accion" and @value="resumir" and not(@disabled)]')->length);
        $this->assertSame(0, $xpath->query('//form[contains(@class,"reports-ai-form")]//button[@name="accion" and @value="preguntar"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//div[@data-reports-date-presets]//button[@type="button" and @data-reports-preset]')->length);
        $this->assertSame(0, $xpath->query('//div[@data-reports-date-presets]//a')->length);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"app-brand") and contains(@aria-label,"panel")]')->length);
        $this->assertSame(0, $xpath->query('//button[.//span[contains(@class,"brand-badge")] or normalize-space()="+"]')->length);
        $this->assertSame(1, $xpath->query('//label[@class="app-menu-toggle" and @for="app-menu-switch"]')->length);
        $this->assertSame(1, $xpath->query('//input[@id="app-menu-switch" and @type="checkbox"]')->length);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_generated_report_keeps_the_question_before_long_results(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.model' => 'test-model', 'openai.base_url' => 'https://provider.invalid/v1']);
        $this->authorizeReports(1, true);
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect(range(1, 30))->map(fn (int $id): object => (object) [
            'id_cita' => $id, 'codigo' => 'DEMO-'.$id, 'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00', 'estado' => 'Programada', 'id_medico' => 4,
            'paciente_nombres' => 'Paciente ficticio', 'paciente_apellidos' => 'Demostración',
            'medico_nombres' => 'Médico ficticio', 'medico_apellidos' => 'Pruebas', 'especialidad_nombre' => 'Prueba',
        ]));
        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1');
        $response->assertOk()->assertSee('DEMO-30');
        $this->saveBrowserFixture('generated', $response->getContent());
        $response->assertSeeInOrder(['Asistente del reporte', 'Resultado del reporte'])
            ->assertDontSee('Ir al asistente IA')
            ->assertSee('Preguntar');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//textarea[@id="pregunta" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@class,"reports-ai-form")]//button[@name="accion" and @value="resumir" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@class,"reports-ai-form")]//button[@name="accion" and @value="preguntar" and not(@disabled)]')->length);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    private function saveBrowserFixture(string $name, string $html): void
    {
        $directory = getenv('CLINIC_BROWSER_FIXTURE_DIR');
        if (is_string($directory) && $directory !== '') {
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_a_date_report_lists_without_clinical_notes(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->withArgs(function (string $start, string $end, ?int $physicianId, ?int $specialtyId, ?string $status): bool {
                return str_starts_with($start, '2026-11-01')
                    && str_starts_with($end, '2026-11-02')
                    && $physicianId === null
                    && $specialtyId === null
                    && $status === null;
            })
            ->andReturn(collect([
                (object) [
                    'id_cita' => 21,
                    'codigo' => 'CIT-1',
                    'inicio' => '2026-11-01T08:00:00+00:00',
                    'fin' => '2026-11-01T08:30:00+00:00',
                    'estado' => 'Programada',
                    'id_medico' => 4,
                    'motivo_consulta' => 'Nota clínica secreta',
                    'paciente_nombres' => 'Ana',
                    'paciente_apellidos' => 'Rojas',
                    'medico_nombres' => 'Luis',
                    'medico_apellidos' => 'Soto',
                    'especialidad_nombre' => 'Pediatría',
                ],
            ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('Criterios aplicados')->assertSee('CIT-1')
            ->assertSee('Total de citas')->assertSee('Resultado del reporte')
            ->assertSee('Exportar')->assertSee('PDF')->assertSee('CSV')->assertSee('Excel')
            ->assertSee('Asistente del reporte')
            ->assertSee('OPENAI_API_KEY')
            ->assertSee('OPENAI_BASE_URL')
            ->assertSee('OPENAI_MODEL')
            ->assertSee('Pediatría')
            ->assertDontSee('Nota clínica secreta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_csv_export_downloads_without_clinical_notes(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->get('/reportes/exportar?tipo=citas_fecha&fecha=2026-11-01&formato=csv');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('CIT-1', $response->streamedContent());
        $this->assertStringNotContainsString('Nota clínica secreta', $response->streamedContent());
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_empty_report_results_are_not_an_error(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->andReturn(collect());

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('No hay datos para la consulta.')
            ->assertSee('Total de citas')
            ->assertSee('Pendientes')
            ->assertDontSee('No fue posible generar el reporte.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_processing_failure_is_not_shown_as_empty_totals(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->andThrow(new RuntimeException('unavailable'));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('No fue posible generar el reporte.')
            ->assertDontSee('No hay datos para la consulta.')
            ->assertDontSee('Total de citas: 0');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_date_report_defaults_to_today_when_fecha_is_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 15:00:00', 'UTC'));
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->withArgs(function (string $start, string $end): bool {
                return str_starts_with($start, '2026-11-11') && str_starts_with($end, '2026-11-12');
            })
            ->andReturn(collect());

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&generar=1')
            ->assertOk()
            ->assertSee('value="2026-11-11"', false)
            ->assertSee('Total de citas');
        $this->assertSame([], $this->app['db']->getConnections());
        Carbon::setTestNow();
    }

    public function test_period_reports_default_to_week_start_through_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 15:00:00', 'UTC'));
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentStatusCountsInRange')->once()
            ->withArgs(function (string $start, string $end): bool {
                return str_starts_with($start, '2026-11-09') && str_starts_with($end, '2026-11-12');
            })
            ->andReturn(collect());

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=totales_periodo')
            ->assertOk()
            ->assertSee('value="2026-11-09"', false)
            ->assertSee('value="2026-11-11"', false)
            ->assertSee('Atendidas');
        $this->assertSame([], $this->app['db']->getConnections());
        Carbon::setTestNow();
    }

    public function test_pdf_export_renders_utf8_table_view(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Cancelada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Lili',
                'paciente_apellidos' => 'Nuñez',
                'medico_nombres' => 'Médico',
                'medico_apellidos' => 'Test',
                'especialidad_nombre' => 'Dermatología',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->get('/reportes/exportar?tipo=citas_fecha&fecha=2026-11-01&formato=pdf');
        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('content-type'));
        $response->assertSee('Nuñez')->assertSee('Médico')->assertSee('Dermatología')
            ->assertSee('Imprimir / Guardar PDF')
            ->assertSee('table-wrap', false)
            ->assertDontSee('Nota clínica secreta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_reports_reject_a_reversed_period_without_sql(): void
    {
        $this->authorizeReports();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/reportes')->get('/reportes?tipo=totales_periodo&fecha_inicio=2026-11-02&fecha_fin=2026-11-01&generar=1')
            ->assertRedirect('/reportes')->assertSessionHasErrors('fecha_inicio');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_unknown_physician_is_rejected_before_listing(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getRegisteredPhysicianById')->once()->with(9)->andReturn(null);
        $appointments->shouldReceive('getAppointmentsInRange')->never();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_medico&id_medico=9&fecha_inicio=2026-11-01&fecha_fin=2026-11-01&generar=1')
            ->assertOk()->assertSee('El médico, la especialidad o el estado seleccionado no es válido para este reporte.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_summarizes_without_clinical_notes(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hay 1 cita programada de Pediatría. No hay canceladas.']],
                ],
            ], 200),
        ]);

        $this->authorizeReports(2);
        $this->mockCatalogLookups(2);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->twice()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->post('/reportes/asistente', [
                'tipo' => 'citas_fecha',
                'fecha' => '2026-11-01',
                'accion' => 'resumir',
                'generar' => '1',
            ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Hay 1 cita programada de Pediatría. No hay canceladas.')
            ->assertSee('Resumen del reporte')
            ->assertDontSee('Nota clínica secreta');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $userContent = (string) ($body['messages'][1]['content'] ?? '');

            return str_contains($request->url(), '/chat/completions')
                && str_contains($userContent, 'CIT-1')
                && ! str_contains($userContent, 'Nota clínica secreta');
        });
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_question_shows_model_answer(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hay 0 canceladas en el reporte.']],
                ],
            ], 200),
        ]);

        $this->authorizeReports(2);
        $this->mockCatalogLookups(2);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->twice()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->post('/reportes/asistente', [
                'tipo' => 'citas_fecha',
                'fecha' => '2026-11-01',
                'accion' => 'preguntar',
                'pregunta' => '¿Cuántas canceladas hubo?',
                'generar' => '1',
            ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Hay 0 canceladas en el reporte.')
            ->assertSee('¿Cuántas canceladas hubo?')
            ->assertDontSee('Nota clínica secreta');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $userContent = (string) ($body['messages'][1]['content'] ?? '');

            return str_contains($request->url(), '/chat/completions')
                && str_contains($userContent, 'CIT-1')
                && ! str_contains($userContent, 'Nota clínica secreta');
        });
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_without_api_key_explains_configuration(): void
    {
        config([
            'openai.api_key' => '',
            'openai.model' => '',
            'openai.base_url' => '',
        ]);
        $this->authorizeReports(2);
        $this->mockCatalogLookups(2);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->twice()->andReturn(collect());

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->post('/reportes/asistente', [
                'tipo' => 'citas_fecha',
                'fecha' => '2026-11-01',
                'accion' => 'resumir',
                'generar' => '1',
            ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('OPENAI_API_KEY')
            ->assertSee('OPENAI_BASE_URL')
            ->assertSee('OPENAI_MODEL');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_requires_permission(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_HISTORY,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->post('/reportes/asistente', [
            'tipo' => 'citas_fecha',
            'fecha' => '2026-11-01',
            'accion' => 'resumir',
        ])->assertForbidden();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_asks_when_instruction_omits_required_dates(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
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

        $this->authorizeReports(2);
        $this->mockCatalogLookups(1);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->never();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes')->post('/reportes/asistente', [
            'accion' => 'resumir',
            'pregunta' => 'Quiero totales de atendidas y canceladas',
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Para continuar, complete:')
            ->assertSee('fecha', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_report_assistant_generates_from_instruction_then_answers(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Hay 0 canceladas en el reporte.'],
                ]],
            ], 200),
        ]);

        $this->authorizeReports(2);
        $this->mockCatalogLookups(2);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->twice()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes')->post('/reportes/asistente', [
            'accion' => 'resumir',
            'pregunta' => 'Armá citas del 2026-11-01',
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Hay 0 canceladas en el reporte.')
            ->assertSee('CIT-1')
            ->assertSee('Preguntar')
            ->assertDontSee('Nota clínica secreta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_generate_replans_when_instruction_changes_after_a_previous_report(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Resumen de totales del período.'],
                ]],
            ], 200),
        ]);

        $this->authorizeReports(2);
        $this->mockCatalogLookups(2);
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->never();
        $appointments->shouldReceive('getAppointmentStatusCountsInRange')->twice()->andReturn(collect([
            (object) ['estado' => 'Atendida', 'total' => 3],
            (object) ['estado' => 'Cancelada', 'total' => 1],
            (object) ['estado' => 'Inasistencia', 'total' => 0],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes?tipo=agenda_diaria&fecha=2026-11-12&generar=1')
            ->post('/reportes/asistente', [
                'tipo' => 'agenda_diaria',
                'fecha' => '2026-11-12',
                'generar' => '1',
                'accion' => 'resumir',
                'pregunta' => 'Totales de atendidas y canceladas del 2026-11-01 al 2026-11-12',
            ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Atendidas, canceladas e inasistencias en un período')
            ->assertSee('2026-11-01 a 2026-11-12')
            ->assertSee('Resumen de totales del período.')
            ->assertSee('name="tipo" value="totales_periodo"', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_ask_button_requires_an_already_generated_report(): void
    {
        config([
            'openai.api_key' => 'test-openai-key',
            'openai.model' => 'gpt-4o-mini',
            'openai.base_url' => 'https://api.openai.com/v1',
        ]);
        Http::fake();
        $this->authorizeReports(2);
        $this->mockCatalogLookups(1);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->from('/reportes')->post('/reportes/asistente', [
            'accion' => 'preguntar',
            'pregunta' => '¿Cuántas canceladas hubo?',
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Genere un reporte primero para poder preguntar.');
        Http::assertNothingSent();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    private function authorizeReports(int $times = 1, bool $fullNavigation = false): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->times($times)->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->times($times)->with($user)->andReturn($fullNavigation
            ? PermissionCatalog::codesForRole(PermissionCatalog::ADMINISTRATOR_ROLE)
            : [PermissionCatalog::GENERATE_REPORTS]);
        $this->app->instance(IdentityRepository::class, $identities);
    }

    private function mockCatalogLookups(int $times = 1): void
    {
        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getRegisteredPhysicians')->times($times)->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Luis', 'apellidos' => 'Soto', 'registro_profesional' => 'MP-1', 'activo' => true],
        ]));
        $this->app->instance(AppointmentRepository::class, $appointments);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getAllSpecialties')->times($times)->with(null)->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getAllPatients')->never();
        $this->app->instance(PatientRepository::class, $patients);
    }
}
