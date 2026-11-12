<?php

namespace Tests\Unit;

use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\Identity\PermissionCatalogService;
use Mockery;
use Tests\TestCase;

class PermissionCatalogServiceTest extends TestCase
{
    public function test_ensure_current_skips_writes_when_the_database_already_matches(): void
    {
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('permissionCatalogIsApplied')->twice()
            ->andReturn(true);
        $repository->shouldReceive('upsertPermissionCatalog')->never();

        $catalog = new PermissionCatalogService($repository);
        $catalog->ensureCurrent();
        $catalog->ensureCurrent();

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_ensure_current_writes_when_a_catalog_code_is_missing(): void
    {
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('permissionCatalogIsApplied')->once()->andReturn(false);
        $repository->shouldReceive('upsertPermissionCatalog')->once()->with(
            PermissionCatalog::roles(),
            PermissionCatalog::permissions(),
            Mockery::on(function (array $assignments): bool {
                return in_array(PermissionCatalog::MANAGE_SCHEDULES, $assignments[PermissionCatalog::ADMINISTRATOR_ROLE], true)
                    && in_array(PermissionCatalog::GENERATE_REPORTS, $assignments[PermissionCatalog::ADMINISTRATOR_ROLE], true)
                    && in_array(PermissionCatalog::CONSULT_ACTIVITY_LOG, $assignments[PermissionCatalog::ADMINISTRATOR_ROLE], true)
                    && $assignments[PermissionCatalog::PHYSICIAN_ROLE] === [
                        PermissionCatalog::MARK_ATTENDED,
                        PermissionCatalog::CONSULT_AGENDA,
                    ]
                    && in_array(PermissionCatalog::PAY_CONSULTATIONS, $assignments[PermissionCatalog::ADMINISTRATOR_ROLE], true)
                    && $assignments[PermissionCatalog::RECEPTIONIST_ROLE] === [
                        PermissionCatalog::MANAGE_PATIENTS,
                        PermissionCatalog::CONSULT_AVAILABILITY,
                        PermissionCatalog::BOOK_APPOINTMENTS,
                        PermissionCatalog::PAY_CONSULTATIONS,
                        PermissionCatalog::RESCHEDULE_APPOINTMENTS,
                        PermissionCatalog::CANCEL_APPOINTMENTS,
                        PermissionCatalog::RECORD_ATTENDANCE,
                        PermissionCatalog::CONSULT_AGENDA,
                        PermissionCatalog::CONSULT_HISTORY,
                    ]
                    && ! in_array(PermissionCatalog::UPDATE_PROFILE, $assignments[PermissionCatalog::ADMINISTRATOR_ROLE], true)
                    && $assignments[PermissionCatalog::PATIENT_ROLE] === [
                        PermissionCatalog::UPDATE_PROFILE,
                        PermissionCatalog::DECLARE_HEALTH,
                        PermissionCatalog::CONSULT_CLINICAL_HISTORY,
                    ];
            }),
        );

        (new PermissionCatalogService($repository))->ensureCurrent();

        $this->assertSame([], $this->app['db']->getConnections());
    }
}
