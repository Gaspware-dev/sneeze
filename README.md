![image](https://github.com/andreherberth/sneeze/blob/main/assets/img/logo.png)
# Sneeze — Drop-in static site studio

A self-contained PHP + SQLite folder you copy into any existing website. Sneeze is the **source of truth** for templates, blocks, CSS, and JavaScript. The public site stays a set of ordinary HTML files: nothing is compiled on visitor request.

See [PLAN.md](PLAN.md) for the redesign architecture.

## Requirements

- PHP 8.2+ with **PDO SQLite** (`php-sqlite3` / `pdo_sqlite`), DOM, JSON, fileinfo
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

```bash
sudo apt install php8.3-sqlite3 php8.3-xml
php -m | grep -iE 'sqlite|dom'
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
- **Studio** — live preview on the left; blocks, writing, and code on the right
- **Templates & blocks** — stored in SQLite, including HTML/CSS/JS for each definition
- **Design tokens** — JSON tokens compile to `:root` CSS variables and are inlined on publish
- **Draft vs publish** — save to the database; publish merges one HTML file per page
- **Backup on publish** — previous files copied to `sneeze/data/backups/`
- **Media library** — studio uploads stay in `sneeze/data/uploads`. On publish, images at or under a configurable size (default 8 KB) are inlined as base64; larger files are copied into the site asset folder
- **Page builder** — drag blocks from the palette onto the outline or the live preview, reorder with the drag handle, and undo/redo edits (toolbar or Ctrl+Z / Ctrl+Shift+Z)
- **HTML import** — turn an existing page into a template + HTML block
- **CMS cache** — fetch JSON from a headless CMS in the admin; visitors never call it
- **Users** — admin and editor roles
- **TypeScript** — page/block/global scripts compile to JS on preview and publish
- **Tailwind CSS** — utility classes in HTML (and `@apply` in CSS) compile against `tailwind.config.ts` and design tokens

## Publish pipeline

On **Publish**, Sneeze concatenates design tokens, global CSS/JS, template CSS/JS, CSS/JS of used block types, and page CSS/JS, then writes a single HTML file. The file does not depend on Sneeze running:

- One minified `<style>` in `<head>` (only CSS for blocks actually on the page). No external stylesheets.
- One minified `<script>` at the end of `<body>` (inline scripts cannot use `defer` without `src`). No external scripts.
- Images at or under **Settings → Inline images** (default `8192` bytes) are embedded as `data:image/...;base64,...`, including CSS `url(...)`. Set the threshold to `0` to never inline. The maximum is 512 KB.
- Larger images, and files that are not images (PDF, video, fonts), are copied into the published asset folder and referenced by a relative path such as `uploads/2026/ab12.png`.
- Markup normalized so the file can pass [validator.w3.org](https://validator.w3.org/)
- TypeScript compiled to JS; Tailwind utilities generated from the page’s classes

Preview uses the same compiler without minifying, plus selection chrome, and keeps media on authenticated studio URLs so drafts are not written to the public folder. Optional: install `esbuild` and `tailwindcss` on the server PATH (or in `sneeze/node_modules`) for the official compilers.

## Data

SQLite file: `sneeze/data/sneeze.db`. It holds users, pages, blocks, templates, design tokens, global CSS/JS, media metadata, CMS cache, audit log, and settings (publish folder, asset folder, inline threshold, site title). The file is created on first request. `data/config.json` is a bootstrap copy updated whenever settings are saved; after startup the database wins.

## Tests

```bash
php tests/run.php
```

The suite covers template rendering and the publish/export step: inline CSS and JS, the image size threshold (including the exact boundary), and separation of larger images and non-image files into the asset folder.

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
