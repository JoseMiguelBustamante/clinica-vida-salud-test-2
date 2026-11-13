# Clinic operational data load
Objective: load realistic (non-demo) specialties, physicians, weekday schedules, patients and 2026 appointments into the clinic PostgreSQL so reports and November availability testing work.
Branch: feat/clinic-bootstrap (test-2) | Delivery: ask-on-risk | Runner: PHPUnit help/safety tests; live load via `php artisan clinic:populate-clinic-data --force` on the clinic DB.

## Specs
S1. User: "Carga datos de reales Pero que no sean demo... O test... Datos como en prod dame ejemplo antes de cargar" — examples approved.
S2. User: "Exacto carga más datos así correcto También carga médicos disponibles en todo el mes de noviembre para no tener problemas al momento de hacer pruebas por test" — load the approved package and ensure physicians have Mon–Sat schedules so November availability works.
S3. No DEMO-/demo./test labels in inserted business rows; idempotent re-run; requires active Administrador; PostgreSQL only; confirmation unless `--force`.

## Tasks
T1 | S1-S3 | inline | done | Command clinic:populate-clinic-data + 4 safety tests GREEN
T2 | S2 | inline | in_progress | Run command against clinic PostgreSQL and verify counts

## Log
L1. User: Exacto carga más datos así correcto También carga médicos disponibles en todo el mes de noviembre para no tener problemas al momento de hacer pruebas por test
L2. Added clinic:populate-clinic-data (4 specialties, 4 physicians MP-*, Mon–Sat hours, 20 patients, ~90 CVS- appointments). Safety tests 4/4. Live DB execution next.
