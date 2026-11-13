# Clinic operational data load
Objective: load realistic (non-demo) specialties, physicians, weekday schedules, patients and 2026 appointments into the clinic PostgreSQL so reports and November availability testing work.
Branch: feat/clinic-bootstrap (test-2) | Delivery: ask-on-risk | Runner: PHPUnit help/safety tests; live load via `php artisan clinic:populate-clinic-data --force` on the clinic DB.

## Specs
S1. User: "Carga datos de reales Pero que no sean demo... O test... Datos como en prod dame ejemplo antes de cargar" — examples approved.
S2. User: "Exacto carga más datos así correcto También carga médicos disponibles en todo el mes de noviembre para no tener problemas al momento de hacer pruebas por test" — load the approved package and ensure physicians have Mon–Sat schedules so November availability works.
S3. No DEMO-/demo./test labels in inserted business rows; idempotent re-run; requires active Administrador; PostgreSQL only; confirmation unless `--force`.
S4. User: "Añade más datos" — denser November detail appointments (CVS2-) so status/list reports for este mes show many rows; “citas atendidas de este mes” plans as citas_estado detail.

## Tasks
T1 | S1-S3 | inline | done | Command clinic:populate-clinic-data + 4 safety tests GREEN
T2 | S2 | inline | done | Live load on web-yn2epv: 90 appointments inserted
T3 | S4 | inline | done | Dense Nov CVS2- (to 28th, 7 slots/physician) + ~40 patients + status-detail planner; tests 19/19

## Log
L1. User: Exacto carga más datos así correcto También carga médicos disponibles en todo el mes de noviembre para no tener problemas al momento de hacer pruebas por test
L2. Added clinic:populate-clinic-data (4 specialties, 4 physicians MP-*, Mon–Sat hours, 20 patients, ~90 CVS- appointments). Safety tests 4/4. Pushed c65da05.
L3. Agent cannot docker exec clinic container; Dokploy API exec not available. Waiting for operator to run `php artisan clinic:populate-clinic-data --force` after deploy.
L4. José ran populate on clinica-vida-salud-web-yn2epv Swarm task: 4 physicians, 20 patients, 90 appointments.
L5. User screenshot: “citas atendidas de este mes” answered against a single-day agenda; asked “Añade más datos”.
