<?php

namespace Tests\Unit;

use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ClinicSchemaTest extends TestCase
{
    public function test_only_the_report_migration_is_available_instead_of_laravel_scaffolding(): void
    {
        $paths = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($paths);

        $this->assertCount(3, $paths);
        $this->assertSame('2026_10_28_000000_create_clinic_schema.php', basename($paths[0]));
        $this->assertSame('2026_11_01_120000_add_bitacora.php', basename($paths[1]));
        $this->assertSame('2026_11_12_120000_add_pago_consulta.php', basename($paths[2]));
        foreach ($paths as $path) {
            $migration = require $path;
            $this->assertInstanceOf(Migration::class, $migration);
            $this->assertTrue($migration->withinTransaction);
        }
    }

    public function test_isolated_runner_explains_its_scope_without_contacting_docker(): void
    {
        $process = new Process(['bash', dirname(__DIR__).'/Integration/run-postgres.sh', '--help']);
        $process->setTimeout(5);
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame("Usage: bash tests/Integration/run-postgres.sh\nRuns the clinic migration integration test on an owned disposable PostgreSQL 17 container.\nRequires Docker access, PHP pdo_pgsql and installed development dependencies.\nPublishes only a random loopback port; never connects to the clinic database.\nUse --identity to run backend authentication and private administrator bootstrap tests.\n", $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_opt_in_integration_test_is_discoverable_without_connecting_to_postgresql(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', '--do-not-cache-result', '--list-tests', dirname(__DIR__).'/Integration']);
        $process->setTimeout(5);
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertStringContainsString('test_report_schema_is_atomic_repeatable_constrained_and_reversible_on_an_empty_fixture', $process->getOutput());
    }

    public function test_identity_fixture_base_loads_without_bootstrapping_a_database_connection(): void
    {
        $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; exit(class_exists(Tests\\Integration\\IdentityPostgresTestCase::class) ? 0 : 1);';
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->setTimeout(5);
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_sql_matches_the_report_physical_schema_without_an_independent_transaction(): void
    {
        $path = dirname(__DIR__, 2).'/database/schema/clinic.sql';
        $this->assertFileExists($path);
        $sql = file_get_contents($path);
        $canonical = trim(preg_replace('/\s+/', ' ', preg_replace('/--[^\n]*/', '', $sql)));

        $this->assertSame('7b2be5ac7f2ed3422c254dfcb40d06e3152c831e27b1df1900f979b0bd6978b0', hash('sha256', $canonical));
        $this->assertSame(19, preg_match_all('/CREATE TABLE public\./', $sql));
        $this->assertSame(14, preg_match_all('/CREATE INDEX /', $sql));
        $this->assertDoesNotMatchRegularExpression('/\b(?:BEGIN|COMMIT|CASCADE)\b/i', $sql);
    }
}
