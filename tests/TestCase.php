<?php

namespace Tests;

use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Identity\ActivityLogRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PanelDashboardService;
use App\Modules\MedicalStaff\PhysicianRepository;
use App\Modules\MedicalStaff\SpecialtyRepository;
use App\Modules\Patients\PatientRepository;
use App\Modules\Schedules\ScheduleRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    private ?string $compiledViews = null;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        // Tests use process configuration only; guarded environment files are never loaded.
        $app->instance(LoadEnvironmentVariables::class, new class
        {
            public function bootstrap(Application $app): void {}
        });

        $app->make(Kernel::class)->bootstrap();

        $this->compiledViews = sys_get_temp_dir().'/clinic-views-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0700);
        $app['config']->set('view.compiled', $this->compiledViews);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CLINIC_DISPOSABLE_PG') === 'yes') {
            return;
        }

        $this->app->instance(ActivityLogRepository::class, Mockery::mock(ActivityLogRepository::class)->shouldIgnoreMissing());

        $dashboardRepositories = array_map(
            static fn (string $repository): object => Mockery::mock($repository)->shouldIgnoreMissing(),
            [PatientRepository::class, PhysicianRepository::class, SpecialtyRepository::class,
                ScheduleRepository::class, AppointmentRepository::class, IdentityRepository::class, ActivityLogRepository::class],
        );
        $this->app->instance(PanelDashboardService::class, new PanelDashboardService(...$dashboardRepositories));

    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->compiledViews !== null) {
                foreach (glob($this->compiledViews.'/*') as $file) {
                    unlink($file);
                }

                rmdir($this->compiledViews);
                $this->compiledViews = null;
            }
        }
    }
}
