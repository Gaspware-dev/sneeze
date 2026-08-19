# AGENTS.md

## Cursor Cloud specific instructions

Sneeze is a single, dependency-free **PHP 8.2+ / SQLite** app (a drop-in static-site studio). There is no Composer/npm manifest, no lockfile, and no build step. The runtime (`php8.3-cli` + `sqlite3`, `xml`/`dom`, `mbstring`, `gd` extensions) is installed by the environment update script; nothing else needs installing.

### Run / test / lint
- Tests: `php tests/run.php` (custom runner; exits non-zero on failure). Its final `w3cErrors` assertion POSTs the published HTML to `validator.w3.org`; it passes when the network is reachable and degrades to "validator unreachable" (which fails that one assertion) when offline.
- Lint: there is no configured linter/formatter. Use PHP's built-in syntax check, e.g. `php -l lib/SomeClass.php` (all files under `lib/`, `views/`, `tests/`, plus `index.php`).
- Build: none. PHP runs directly; front-end assets are served as-is (CodeMirror loads from a CDN).

### Running the app locally (non-obvious)
- PHP's built-in server ignores `.htaccess`, so it needs a router to route non-static requests through `index.php`. A dev helper `router.php` is committed at the repo root for this. Serve the repo root as the app:
  - `php -S 0.0.0.0:8080 -t /workspace router.php`, then open `http://localhost:8080/`.
- On first visit the setup wizard runs. It writes `data/config.json` and creates `data/sneeze.db`. The whole `data/` dir is gitignored and generated at runtime — **delete `data/` to reset setup** and get the wizard back.
- Setup wizard gotcha: the site-folder directory browser is restricted to *within the parent of the app dir* and it blocks the app's own tree, hidden dirs, and dirs named `data`/`node_modules`/`vendor`. So the "published site root" you pick must be a writable directory **outside** the repo. A convenient choice on the VM is `/home/ubuntu/site` (create it if missing). Published HTML (e.g. `index.html`) is written there on Publish.

### Optional toolchain
- `esbuild` (TypeScript) and `tailwindcss` (CSS) are auto-detected on `PATH` / `node_modules/.bin` and used if present; otherwise built-in PHP fallbacks (`TypeScriptCompiler`, `TailwindCompiler`) handle compilation. Neither is required to run or test the app.
