# Step 1 Plan: S7 — Docker setup

> Companion to `STRETCH_GOALS_PLAN.md` §5 (S7) and §2 (Implementation Ordering).
> Step 1 is S7 (Docker) — the first goal in the Implementation Order. It is pure
> infra with zero app-logic dependency, so it unblocks running the rest of the
> stretch goals inside Docker.

## Status

| Item | Status | Detail |
|---|---|---|
| App runs natively | Done | `php -S localhost:8000 -t public public/router.php`; SQLite at `data/minirank.sqlite` (git-ignored). |
| `Dockerfile` | To do | `php:8-cli`, enable `pdo_sqlite`, `WORKDIR /app`, install source. |
| `docker-compose.yml` | To do | one `web` service, port 8000, bind-mount `./data`. |
| `.dockerignore` | To do | Exclude `.git/`, `data/`, `.idea/`, etc. |
| `README.md` (Docker section) | To do | Add under "Setup". |

## Decisions adopted from STRETCH_GOALS_PLAN

- Single service, PHP built-in server — **no MySQL** (avoids env-based DB
  credentials; respects AGENTS rule 3: no committed secrets).
- Host bind-mount `./data:/app/data` so the SQLite file persists on the host
  (same path as the native `php -S` startup).
- No `composer install` in the image — S6 (PHPUnit) runs on the host, not in
  the container (confirmed in STRETCH_GOALS_PLAN §5/§12).
- Source is **COPY'd into the image** (Decision: Option A), not bind-mounted,
  because the compose file only mounts `./data` and the image must be
  self-contained.

## Path resolution (verified against current source)

With `WORKDIR /app` and `COPY . /app`, all `__DIR__`-relative requires resolve:

| File | Resolved-from | Resolved-to |
|---|---|---|
| `public/router.php` → `__DIR__ . '/index.php'` | `/app/public` | `/app/public/index.php` |
| `public/index.php` → `__DIR__ . '/../src/db.php'` | `/app/public` | `/app/src/db.php` |
| `public/index.php` → views `__DIR__ . '/../views/...'` | `/app/public` | `/app/views/...` |
| `src/db.php` → `DATA_DIR = __DIR__ . '/../data'` | `/app/src` | `/app/data` |

`/app/data` matches the `./data:/app/data` bind mount, so a database seeded in
the container lands on the host at `data/minirank.sqlite` and vice-versa.

## The `pdo_sqlite` gap (flag)

`php:8-cli` (Debian) does **not** ship `pdo_sqlite` pre-installed. It must be
compiled via `docker-php-ext-install pdo_sqlite`. PHP bundles the SQLite
amalgamation in its source, so `libsqlite3-dev` is **not** strictly required —
but if compilation fails, the fallback is
`apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev`
before the `docker-php-ext-install` line. Will verify at build time.

## Files to create / edit

### 1. `Dockerfile` (new, repo root)

```dockerfile
FROM php:8-cli

# pdo_sqlite is not in the base image. The official PHP Debian image compiles
# the driver against the SYSTEM sqlite3 library (via pkg-config), not a bundled
# amalgamation, so we must install libsqlite3-dev first.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev; \
    docker-php-ext-install pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Package the app into the image. .dockerignore keeps it lean.
COPY . /app

EXPOSE 8000

# Standalone run uses the same command compose uses; compose may override it.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
```

- Verified: `docker build` succeeds; `pdo_sqlite` + `sqlite3` load in-container.
- No secrets baked in (SQLite → no credentials). No `composer install` (per
  STRETCH_GOALS_PLAN §5).

### 2. `.dockerignore` (new, recommended)

Without this, `COPY . /app` drags `.git/` (history bloat) + the seeded
`data/minirank.sqlite` into the image.

```
.git/
data/
vendor/
.phpunit.cache/
.idea/
.vscode/
*.sqlite
*.sqlite3
```

### 3. `docker-compose.yml` (new, repo root)

```yaml
services:
  web:
    build: .
    ports:
      - "8000:8000"
    volumes:
      - ./data:/app/data
    command: php -S 0.0.0.0:8000 -t public public/router.php
```

- No `version:` key — Compose v2 ignores/deprecates it.
- `command:` overrides the Dockerfile `CMD` (both run the same server; fine).
- Only `./data` is mounted; source lives in the image (Option A).

### 4. `README.md` — add a `### Docker` subsection under `## Setup`, after the
native `php -S` instructions:

```markdown
### Docker

```bash
docker compose up            # first run builds the image
# then open http://localhost:8000
```

To regenerate the demo database from inside the container (CLI, not an HTTP
request):

```bash
docker compose run --rm web php seed.php
```
```

## Security alignment (STRETCH_GOALS_PLAN §11, M6)

| Rule | S7 impact |
|---|---|
| Parameterized SQL | No new queries; app code unchanged. |
| Escape output | No new output; app code unchanged. |
| No secrets committed | No `.env`, no DB credentials (SQLite only). `.dockerignore` keeps `data/` out of the image. |
| No DB writes via GET | `seed.php` runs via `docker compose run ... php seed.php` (a CLI invocation), **not** an HTTP GET. Respects AGENTS rule. |
| `php -S` built-in server | Dev-only server, exactly as documented for the native start (M7). |

## What this step does **not** touch (stays small)

- No schema changes (`seed.php` untouched).
- No helper/route/view changes.
- No CSRF/auth (S3), no projects (S2) — pure infra, zero app-logic dependency.

## Verification (run after implementation)

```bash
# 1. Validate compose syntax
docker compose config

# 2. Build the image (verifies pdo_sqlite compiles)
docker build -t minirank:test .

# 3. Start
docker compose up -d        # expect: "listening on http://0.0.0.0:8000"

# 4. HTTP smoke tests
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/                  # 200
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/1         # 200
curl -s http://localhost:8000/ | grep -o "MiniRank"                               # MiniRank

# 5. Static asset through the router path-traversal guard
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/css/style.css  # 200

# 6. sqlite driver present inside container
docker compose run --rm web php -m | grep -i pdo_sqlite   # expect: pdo_sqlite

# 7. Seeding in-container (CLI, idempotent; lands on host bind-mount)
docker compose run --rm web php seed.php   # expect: "Seeded 10 keywords, 300 positions..."
ls -l data/minirank.sqlite                # host file, size > 0

# Stop & clean up
docker compose down
```

## Suggested commit

```
git add Dockerfile docker-compose.yml .dockerignore README.md
git commit -m "Add Docker setup (compose) for local dev with built-in PHP server"
```

## Files affected

- New: `Dockerfile`
- New: `docker-compose.yml`
- New: `.dockerignore`
- Edited: `README.md` (+Docker subsection)
