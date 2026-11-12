# Clínica Vida Salud — bootstrap

PHP 8.3+ / Laravel 13, PostgreSQL only. The accepted architecture is a modular
monolith with server-rendered Blade, HTML, CSS and JavaScript. Approved modules:
Identity, Patients, MedicalStaff, Schedules and Appointments under `app/Modules`.
Identity provides the session-authentication backend, the staff login/panel
screens, CU03 account administration, CU17 patient portal registration and CU18
profile preferences. Patients provides CU04 record
administration and CU19 declared medical information. MedicalStaff provides CU05 physicians and CU06 specialties.
Schedules provides CU07 weekly hours. Appointments owns the public foundation
page, CU08–CU16 appointment operations, CU20 authorized clinical history
for the patient portal and CU21 appointment reminders.

## Backend authentication (deployed; private bootstrap pending)

CU01/CU02 backend is deployed with Laravel web sessions and CSRF, plus the private,
manually invoked initial-administrator command. The operator's post-fix disposable
PostgreSQL run passed **43 tests, 521 assertions, exit 0**. Production deployment
`kRaBGJACL0JXrZvUhKNNe` completed from source `5f1553b`, including the private
console Enter-delimiter correction. That adapter-only fix passed the real-reader
regression, the 89-test default suite (1,012 assertions), and independent CLI
verification (34 tests, 479 assertions). The earlier PostgreSQL proof remains
applicable because database/authentication logic is unchanged; no new PostgreSQL
run is claimed. No migration or seed ran during deployment, and no real account
or authentication screen was created.

Live HTTPS checks passed for CSRF bootstrap, a Secure/HttpOnly/SameSite=Lax session
cookie, private/no-store responses, guest-session rejection, CSRF rejection, and a
valid-CSRF login attempt for a random nonexistent identity against PostgreSQL.
Public routes/assets and private-file isolation remained intact. **Operator-only
administrator creation and positive production login/session/logout checks remain
pending**; an anonymous/failed login is not proof of successful authentication.

- Contract and limitations: `app/Modules/Identity/README.md`.
- Hidden-password operator command: `deploy/initial-admin.md`.
- Tracking and evidence: `odd/tasks/clinic-authentication.md`.

Run the existing owned/disposable PostgreSQL fixture in Identity mode:

```sh
sudo bash /home/agent/projects/clinica-vida-salud/tests/Integration/run-postgres.sh --identity
```

This targets 43 authored Identity integration cases for sessions/CSRF, active
accounts, exact identifiers and private bootstrap/transaction/lock behavior.
Discovery alone is not a pass. The default runner without `--identity` still runs only the
migration test. The default DB-independent suite remains separate.
Never point these tests at the clinic database or seed a default administrator.
Production already had `SESSION_SECURE_COOKIE=true`; it was verified before
upload without changing runtime variables. Actual HTTPS response cookies confirmed
Secure, HttpOnly and SameSite=Lax. Retain these settings; do not weaken cookies to
make login work.

## Modular foundation

| Surface | Responsibility |
| --- | --- |
| `bootstrap/providers.php` | Registers `ModuleServiceProvider` with Laravel. |
| `app/Providers/ModuleServiceProvider.php` | Explicitly loads existing `routes/web.php` files for the five approved modules using Laravel's cache-aware route loader. |
| `app/Modules/Appointments/routes/web.php` | Owns the public `GET /` route, named `home`, with `web` middleware. Booking is not implemented. |
| `resources/views/layouts/clinic.blade.php` | Shared semantic Blade shell and skip link. |
| `resources/views/home.blade.php` | Public landing with access links; no patient or medical data. |
| `public/css/clinic.css` | Local responsive stylesheet, served directly without a frontend build. |

Identity and Appointments now have module directories for their implemented routes.
Add remaining module directories only when implementing their approved behavior; the
provider already recognizes their exact names. Module route files must explicitly
apply their middleware (such as `web`) and choose unique route names and URLs.
`routes/web.php` is reserved for future cross-module routes; it does not duplicate
`/`. Laravel's existing `/up` health endpoint remains unchanged and is not a
PostgreSQL readiness check.

No JavaScript is needed for this static page. Node/npm and Vite are not required to
render it. When interactive features justify bundling, migrate the stylesheet to
`resources` and use Blade's Vite integration with the existing frontend pipeline;
do not add a mandatory build to this initial slice.

Check the foundation without environment files or a database:

```sh
vendor/bin/phpunit --do-not-cache-result tests/Feature/ModuleFoundationTest.php
vendor/bin/phpunit --do-not-cache-result tests/Feature/ExampleTest.php
vendor/bin/phpunit --do-not-cache-result
```

These tests cover root/module registration, semantic HTML, local asset references,
unavailable business endpoints and preservation of `/up`. Browser-based visual
and keyboard checks remain pending; HTTP tests do not establish visual fidelity.

## Safe local bootstrap

Prerequisites: PHP with Laravel's extensions and `pdo_pgsql`, and Composer. Node/npm
is needed only for future bundled frontend assets. Check the driver without connecting to a database:

```sh
php -v
composer -V
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
composer install --no-scripts --no-interaction
composer validate --strict --no-check-publish
vendor/bin/phpunit --do-not-cache-result
```

Dependencies and Laravel Boost have been installed. Boost's generated guidelines
are in `CLAUDE.md`; read those as well as `AGENTS.md` before changes.
`--no-scripts` is the guarded bootstrap path: it avoids application hooks while
configuration is still pending. The unchanged package discovery/asset publication
hooks may run only once environment provisioning is approved and ready.

### Environment provisioning (manual, not performed)

`.env` and `.env.example` remain guard-blocked: do not read, copy or generate them
with bootstrap commands. An authorized operator must provision process environment
variables through their local secret manager or, later, deployment configuration.
Supply a unique secret `APP_KEY` generated outside this repository; never use the
public, fixed PHPUnit key outside tests. Set `APP_ENV=local`, `APP_DEBUG=true` only
locally, and an appropriate `APP_URL`. Production `APP_URL` must be the public
`https://` origin (the TLS hostname). Use `APP_DEBUG=false` for production.

Set `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT` (normally 5432), `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD` and the appropriate `DB_SSLMODE`. Keep credentials out
of source control, command transcripts and shared logs. No SQLite fallback exists;
`AppServiceProvider` restricts the booted connection list to PostgreSQL after Laravel
merges its framework defaults. Laravel's PostgreSQL connector still requires the
real PDO driver. The boot-level regression runs without opening connections:

```sh
vendor/bin/phpunit --do-not-cache-result tests/Feature/PostgresConfigurationTest.php
```

Local defaults are `SESSION_DRIVER=file`, `CACHE_STORE=file`,
`QUEUE_CONNECTION=sync`, `QUEUE_FAILED_DRIVER=null`: no sessions/cache/jobs tables
are needed. Files are local to one instance; durable queues, shared storage,
batching and failed-job retention require a later infrastructure/schema decision.
The default tests use array sessions/cache, sync queues and PostgreSQL configuration;
they do not connect to a database or load environment files. Opt-in migration tests
live outside the default suite in `tests/Integration`; they require the owned,
disposable PostgreSQL fixture described below, never SQLite or production credentials.

After manual provisioning, `composer run setup` retains dependency installation and
frontend install/build only; it does not copy environment files, generate keys,
create databases or migrate. `composer run dev` and `composer run test` retain their
upstream behavior and require a provisioned environment. No frontend build or
long-running development server was started in this slice.

## Production container (Dokploy)

The root `Dockerfile` uses PHP 8.3/Apache Bookworm and Composer 2. It installs
`pdo_pgsql` and the application extensions, installs locked production dependencies
with `--no-dev --no-scripts`, and serves only `/var/www/html/public` on port **80**.
Apache rewrite rules route requests through the existing public entrypoint; the
stylesheet at `/css/clinic.css` needs no Node/Vite build. PHP errors go to stderr,
not HTTP responses. There is no debug, phpinfo or database administration endpoint.

### Source upload contract

Commit the reviewed Dockerfile, `.dockerignore`, `deploy/` configuration and
application source before preparing a ZIP from `git archive` of that commit.
Uncommitted/untracked files are not included. The upload must contain the project
files at its root, including `composer.lock`, `artisan`, `app/`, `bootstrap/`,
`config/`, `database/`, `public/` (including `.htaccess`), `resources/`, `routes/`
and `deploy/`. No separate packaging script or frontend artifact is needed.

`.dockerignore` is default-deny: only those application/build inputs are admitted.
It keeps environment files, credentials, keys, logs, host caches, `.git`, `vendor`,
`node_modules`, tests, ODD documents and agent configuration out of the build
context/image. Docker ignore rules do **not** filter the uploaded ZIP: the operator
must separately inspect its entry names and reject secret/environment files before
upload. Never add guarded environment files to Git or the archive. Dependencies
are installed inside the image rather than uploaded from the workstation.

### Runtime configuration and persistence

In Dokploy choose the Dockerfile build at the source root and container port 80.
Provide `APP_ENV=production`, `APP_DEBUG=false`, a unique runtime `APP_KEY`, the
public `APP_URL`, and the PostgreSQL variables listed above via deployment secrets.
Do not place them in build arguments, source or transcripts. Keep `LOG_CHANNEL=stderr`,
`SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` and
`QUEUE_FAILED_DRIVER=null`. Use a new isolated internal PostgreSQL service, not an
existing application's database. No separate frontend service is required.

For the optional CU16 report assistant, set `OPENAI_API_KEY` from
[platform.openai.com](https://platform.openai.com) (API billing; ChatGPT Plus is
not enough). Optional: `OPENAI_MODEL=gpt-4o-mini`, `OPENAI_BASE_URL`,
`OPENAI_TIMEOUT`. Without the key the report UI still works; the assistant block
explains that it is not configured.

Persist **`/var/www/html/storage`** for file sessions, cache and future application
files. Before starting Apache, the mount must contain `app/private`, `app/public`,
`framework/cache/data`, `framework/cache/locks`, `framework/sessions`,
`framework/views` and `logs`, writable by `www-data` (Debian UID/GID 33).
The image creates these paths; an empty bind mount can hide them, so the operator
must initialize it or use a volume that copies the image's initial contents.
`/var/www/html/bootstrap/cache` is also image-initialized and writable by `www-data`;
keep it writable and deployment-local, not shared with older releases. A read-only
root filesystem needs a separate writable mount there with correct ownership.
No storage symlink is created: public user uploads are not implemented.

Startup runs **Apache only**. It never copies environment files, generates a key,
runs migrations, or caches configuration before runtime secrets exist. Composer
scripts/package discovery are deliberately not run at build time. Laravel can
create its package manifest on first runtime boot in writable `bootstrap/cache`.
The three unused upstream migrations have been replaced by the report-aligned
migration described below. Migration execution remains explicit, never automatic.

The image health check requests `http://127.0.0.1/up` with bounded timeouts. This
checks Laravel boot, **not PostgreSQL readiness**. After deployment, verify `/`,
`/css/clinic.css` and `/up` over HTTP and independently verify the isolated database
connection. Local structural tests are not proof of a successful image build or
live deployment; the actual Dokploy build/runtime checks remain required.

```sh
vendor/bin/phpunit --do-not-cache-result tests/Unit/DeploymentConfigurationTest.php
vendor/bin/phpunit --do-not-cache-result
composer validate --strict --no-check-publish
```

## Database and delivery boundary

A dedicated PostgreSQL 17 service and the Laravel application have been deployed
in Dokploy. Its runtime health check extends the image check with an authenticated
PDO `SELECT 1`: the application container is healthy and database login is proven.
Both services have named persistent volumes; PostgreSQL has no published external
port. The authentication application redeployment retained its configured database
and successfully queried the existing `usuario` table. Persistence of real
accounts, authenticated sessions or clinical records across redeployment remains
untested; deployment did not create any such records.

**Public URL:** <https://clinica.187.127.19.244.sslip.io/>.
HTTPS `/`, `/css/clinic.css` and `/up` returned 200 with certificate validation;
the stylesheet matched the committed source. HTTP redirects to HTTPS (301), and
private environment/source/log paths return 404. Browser visual and keyboard
checks remain manual; HTTP checks do not prove appearance.

The operator restored missing shared Traefik from its existing configuration after
creating a private backup at `/etc/dokploy/backups/traefik-20261028T135937Z`.
Configuration hashes stayed unchanged; Electrónica's frontend and API `/health`
also returned HTTPS 200. Proxy ports bind only to `187.127.19.244` so Tailscale can
retain `100.125.224.92:443`. Preserve those explicit binds during future proxy
re-creation; do not replace them with wildcard bindings without checking conflicts.

The report migration has been applied to the clinic database by the authorized
operator: both source digests matched, the target/empty-schema preflight passed,
execution finished `DONE`, and migration status reports `[1] Ran`. This creates
17 business tables plus Laravel's `migrations` bookkeeping table, without seeds.
The operator's disposable PostgreSQL integration output showed a pass marker and
1/1 completed; its final assertion count/exit code were not supplied. The parent
also verified public `/` and `/up` return HTTPS 200 after the schema change.
The session-authentication backend is now deployed as described above; clinical
business workflows remain unimplemented. Initial administrator provisioning is
still manual and pending. See `odd/tasks/clinic-authentication.md` for current
authentication/deployment evidence, `odd/tasks/clinic-dokploy.md` for infrastructure,
and `odd/tasks/clinic-migrations.md` for schema execution evidence.

## Report-aligned backend migrations

`database/migrations/2026_10_28_000000_create_clinic_schema.php` executes
`database/schema/clinic.sql` inside Laravel's PostgreSQL transaction. The SQL
preserves the report's physical script (PDF pages 126–135), including identity
columns, nullability, named PK/FK/unique/check constraints and 11 explicit indexes:

- Identity: `rol`, `permiso`, `usuario`, `rol_permiso`.
- People/staff: `paciente`, `medico`, `especialidad`, `medico_especialidad`.
- Scheduling: `horario_atencion`, `cita_medica`.
- Supporting records: `informacion_medica`, `contacto_emergencia`,
  `preferencias_notificacion`, `preferencias_privacidad`, `registro_atencion`,
  `recordatorio_cita`, `evento_cita`, `bitacora`.

The activity log table `bitacora` records accesses and field-level changes for
the administrative consult screen. Laravel also creates its **`migrations`
bookkeeping table**; it is not a clinic business table. No users, passwords, roles or clinical records are seeded. The
report's deferred rules (such as preventing overlapping bookings transactionally)
remain future backend work. This migration unit itself adds no authentication or
frontend feature; the separately implemented authentication backend uses these
existing tables.
Rollback drops clinic tables without `CASCADE`: use it only on the disposable test
fixture, not on a database containing real records. Never run `migrate:fresh`,
`migrate:reset`, `db:wipe`, `--seed` or the removed scaffold migrations in production.

### 1. Run the isolated PostgreSQL proof

On this VPS, the operator can run the committed test runner with administrator
Docker access, without changing socket permissions or exposing the clinic database:

```sh
sudo bash /home/agent/projects/clinica-vida-salud/tests/Integration/run-postgres.sh
```

Run `bash tests/Integration/run-postgres.sh --help` for requirements. It creates a
randomly named PostgreSQL 17 container with no persistent volumes, a temporary
in-memory data directory and an ephemeral port bound **only to 127.0.0.1**. Its
random credentials stay in process variables, not arguments/files/output. It stops
only its own fixture on exit; Docker removes that fixture automatically. Existing
containers, networks and volumes are not removed. It refuses cached Laravel
configuration. PHP, `pdo_pgsql`, Docker and the installed development dependencies
must already be present; it installs nothing.

The opt-in test checks the public Artisan migration/rollback commands, every
column's type/nullability/identity, constraint and index names, idempotent migrate,
valid related rows, rejected updates leaving rows and sequences unchanged, rollback
and atomic failure on a conflicting fixture table. These opt-in tests are **not**
part of the default database-independent suite. Missing Docker access is a
blocker, not a pass.

### 2. Apply only the deployed report migration

**Stop if the isolated test fails.** Source `9881a62` has been deployed successfully
with these inactive migration files; the new container is healthy and public `/`
and `/up` return HTTPS 200. No migration command was issued during deployment.
The following preflight checks source digests and stops rather than falling back
to upstream migrations if files differ or are absent. Do not copy files into a
running container. The operator subsequently executed the guarded migration and
reported `[1] Ran`; the commands below are retained as the initial-provisioning
procedure, **not a request to run them again**. Their empty-schema check should
now stop on this already-provisioned database. Do not bypass that check or reset
any tables. Container health alone does not prove schema execution; the operator's
migration/status output provides that evidence.

```sh
(
  set -eu
  ids=$(sudo docker ps -q --filter 'label=com.docker.swarm.service.name=clinica-vida-salud-web-yn2epv')
  set -- $ids
  if [ "$#" -ne 1 ]; then
    echo 'STOP: expected exactly one running clinic application container.' >&2
    exit 1
  fi
  app="$1"
  sudo docker exec -w /var/www/html "$app" test -f database/migrations/2026_10_28_000000_create_clinic_schema.php
  sudo docker exec -w /var/www/html "$app" test -f database/schema/clinic.sql
  printf '%s\n' \
    'f29dfeed3f044f6655b65f6f0752bbcfcf087fda4084be57fb1e450e8d157e84  database/migrations/2026_10_28_000000_create_clinic_schema.php' \
    '09032b51e4ca0a59d1bcd652bad276e112f2fe655a7cdcf9c749e582c69a657a  database/schema/clinic.sql' \
    | sudo docker exec -i -w /var/www/html "$app" sha256sum --check -
  sudo docker exec -w /var/www/html "$app" php -r '
    if (getenv("DB_CONNECTION") !== "pgsql"
        || getenv("DB_DATABASE") !== "clinica_vida_salud"
        || getenv("DB_HOST") !== "clinica-vida-salud-postgres-gze97v"
        || getenv("DB_URL") || getenv("APP_CONFIG_CACHE")
        || is_file("bootstrap/cache/config.php")) {
        fwrite(STDERR, "STOP: unexpected clinic database configuration.\n"); exit(1);
    }
    $pdo = new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: "5432").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = '\''public'\'' AND tablename <> '\''migrations'\''")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables !== []) {
        fwrite(STDERR, "STOP: database is not empty; inspect schema before proceeding.\n"); exit(1);
    }
    echo "Clinic database target verified; no business tables exist.\n";
  '
  sudo docker exec -w /var/www/html "$app" php artisan migrate --force --no-interaction \
    --path=database/migrations/2026_10_28_000000_create_clinic_schema.php
  sudo docker exec -w /var/www/html "$app" php artisan migrate:status --no-interaction \
    --path=database/migrations/2026_10_28_000000_create_clinic_schema.php
)
```

Do not print runtime environment, raw Docker inspect data, connection strings or
passwords when sharing results. Send only test/migration status. If a preflight
fails, stop rather than retrying with broader paths or destructive commands.
