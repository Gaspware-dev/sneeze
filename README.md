![image](https://github.com/andreherberth/sneeze/blob/main/assets/img/logo.png)
# Sneeze — Drop-in static site studio

A self-contained PHP + SQLite folder you copy into any existing website. Sneeze is the **source of truth** for templates, blocks, CSS, and JavaScript. The public site stays a set of ordinary HTML files: nothing is compiled on visitor request.

See [PLAN.md](PLAN.md) for the redesign architecture.

## Requirements

- PHP 8.2+ with **PDO SQLite** (`php-sqlite3` / `pdo_sqlite`), DOM, JSON, fileinfo
- Optional for self-update: **zip** (`php-zip` / `ZipArchive`) — required only when using **Update Sneeze**
- Apache with `mod_rewrite` (or nginx equivalent), or PHP built-in server for local dev
- PHP write access to the folder where published HTML should land (and the published asset folder)

## Installation

1. Copy the entire `sneeze/` folder into your site (e.g. next to where `index.html` will be published):

```
yoursite/
├── index.html       ← written on Publish
├── uploads/         ← published assets (configurable)
└── sneeze/          ← copy here
    └── data/uploads ← studio drafts (not public)
```

2. Visit `https://yoursite.example/sneeze/` in your browser.

3. Complete the setup wizard:
   - **Step 1:** Choose the writable folder for published HTML (defaults to `../`)
   - **Step 2:** Set the public URL path (usually `/`) and the **published asset folder** (e.g. `uploads` or `assets`)
   - **Step 3:** Create your admin account

Studio uploads go to `sneeze/data/uploads` and are not public. **Publish** copies files used on a page into `{site}/{asset-folder}/`. Existing HTML with the same names is backed up under `sneeze/data/backups/` first.

You can **import a preexisting HTML file** from the studio (or Library): Sneeze builds a template from the document chrome (`<header>`, `<nav>`, `<footer>`, …), puts `<main>` into an HTML block on a new page, and copies local images into the studio library.

### PHP extensions

| Extension | Package (Ubuntu/Debian) |
|-----------|-------------------------|
| `pdo_sqlite` | `php8.3-sqlite3` |
| `dom` (DOMDocument) | `php8.3-xml` |
| `fileinfo` | usually bundled with PHP |
| `zip` (optional) | `php8.3-zip` |

```bash
sudo apt install php8.3-sqlite3 php8.3-xml php8.3-zip
php -m | grep -iE 'sqlite|dom|zip'
```

Optional: `php8.3-gd` for upload thumbnails.

## Local development

PHP's built-in server does not read `.htaccess`. Save a `router.php` file in your site root (alongside the `sneeze/` folder), then start the server from there:

```bash
php -S localhost:8080 router.php
```

Then open `http://localhost:8080/sneeze/`.

### Dev router (`router.php`)

Create this file at your site root if you use PHP's built-in server:

```php
<?php
// Dev router for PHP built-in server — not needed on Apache/nginx with .htaccess
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/sneeze')) {
    $sneezeUri = substr($uri, strlen('/sneeze')) ?: '/';
    $_SERVER['SCRIPT_NAME'] = '/sneeze/index.php';
    $_SERVER['REQUEST_URI'] = '/sneeze' . ($sneezeUri === '/' ? '/' : $sneezeUri);
    chdir(__DIR__ . '/sneeze');
    require __DIR__ . '/sneeze/index.php';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

if ($uri === '/' || $uri === '') {
    $index = __DIR__ . '/index.html';
    if (is_file($index)) {
        readfile($index);
        return true;
    }
}

http_response_code(404);
echo 'Not found';
return true;
```

## Features

- **Drop-in subfolder** — does not take over site serving
- **Versioned updates** — Settings → Update Sneeze accepts a GitHub **Code → Download ZIP**; `data/` is preserved
- **Studio** — live preview on the left; blocks, writing, and code on the right
- **Templates & blocks** — stored in SQLite, including HTML/CSS/JS for each definition
- **Site chrome** — sitewide header/footer in Design; pages can inherit, override, or hide them
- **Design tokens** — JSON tokens compile to `:root` CSS variables and are inlined on publish
- **Global CSS/JS** — Design stylesheets and scripts, with per-page excludes
- **Draft vs publish** — save to the database; publish merges one HTML file per page
- **Backup on publish** — previous files copied to `sneeze/data/backups/`
- **Media library** — studio uploads stay in `sneeze/data/uploads` until publish copies used files into the site asset folder
- **HTML import** — turn an existing page into a template + HTML block
- **CMS cache** — fetch JSON from a headless CMS in the admin; visitors never call it
- **Users** — admin and editor roles
- **TypeScript** — page/block/global scripts compile to JS on preview and publish
- **Tailwind CSS** — utility classes in HTML (and `@apply` in CSS) compile against `tailwind.config.ts` and design tokens

## Updating Sneeze

1. On GitHub, open the repo → **Code** → **Download ZIP** (or use a release zip with the same layout).
2. In the studio, open **Settings → Update Sneeze** and upload that zip.
3. Sneeze extracts the package (including GitHub’s nested `owner-repo-sha/` folder), overlays app files, and leaves `sneeze/data/` alone (config, SQLite, uploads, backups).
4. Downgrades are refused when the package `VERSION` is older than the installed version.

Current version is shown in Settings (starts at **v2.0.0-alpha1**).

## Publish pipeline

On **Publish**, Sneeze concatenates design tokens, global CSS/JS, template CSS/JS, CSS/JS of used block types, and page CSS/JS, then writes a single HTML file:

- One minified `<style>` in `<head>` (only CSS for blocks actually on the page)
- One minified `<script>` at the end of `<body>` (inline scripts cannot use `defer` without `src`)
- Markup normalized so the file can pass [validator.w3.org](https://validator.w3.org/)
- TypeScript compiled to JS; Tailwind utilities generated from the page’s classes

Preview uses the same compiler without minifying, plus selection chrome. Optional: install `esbuild` and `tailwindcss` on the server PATH (or in `sneeze/node_modules`) for the official compilers.

## Security

- Session cookies scoped to `/sneeze/`
- CSRF protection on mutating requests
- Path traversal blocked on published-file writes
- `data/`, `lib/`, `views/` blocked via `.htaccess`

## Removing Sneeze

Delete the `sneeze/` folder. Your last published HTML files and the published asset folder remain. Drafts, unpublished media, backups, and the database inside `sneeze/data/` are removed with the folder.

## Apache configuration

If rewrite rules do not work, ensure `AllowOverride All` is set for the directory. The included `.htaccess` routes all requests through `index.php`.

## nginx snippet

```nginx
location /sneeze/ {
    try_files $uri $uri/ /sneeze/index.php?$query_string;
}
```
