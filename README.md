# Sneeze — Drop-in HTML Admin Panel

A self-contained PHP + SQLite subfolder you copy into any existing flat-file HTML website. The live site keeps working unchanged; Sneeze only provides authenticated editing, backups, media uploads, and preview.

## Requirements

- PHP 8.2+ with **PDO SQLite** (`php-sqlite3` / `pdo_sqlite` extension), DOM, JSON
- Apache with `mod_rewrite` (or nginx equivalent), or PHP built-in server for local dev
- PHP write access to your HTML files and upload directory

## Installation

1. Copy the entire `sneeze/` folder into your site (e.g. next to `index.html`):

```
yoursite/
├── index.html
├── assets/
└── sneeze/          ← copy here
```

2. Visit `https://yoursite.example/sneeze/` in your browser.

3. Complete the setup wizard:
   - **Step 1:** Browse to the folder containing your HTML files (defaults to `../`)
   - **Step 2:** Set the public URL path (usually `/`)
   - **Step 3:** Create your admin account

No changes to your existing site, `.htaccess`, or nginx config are required for a standard install.

### PHP extensions

Sneeze needs these PHP extensions:

| Extension | Package (Ubuntu/Debian) |
|-----------|-------------------------|
| `pdo_sqlite` | `php8.3-sqlite3` |
| `dom` (DOMDocument) | `php8.3-xml` |
| `fileinfo` | usually bundled with PHP |

Install in one step:

```bash
sudo apt install php8.3-sqlite3 php8.3-xml
php -m | grep -iE 'sqlite|dom'
```

Restart your web server or dev server after installing.

Optional: `php8.3-gd` for upload thumbnails.

## Local development

PHP's built-in server does not read `.htaccess`. Use the included dev router from your site root:

```bash
# From demo-site/ (or your site root with sneeze/ alongside HTML files)
php -S localhost:8080 router.php
```

Then open `http://localhost:8080/sneeze/`.

On Apache/nginx, `.htaccess` in `sneeze/` handles routing automatically.

A demo site is included at `demo-site/` for testing.

## Features

- **Drop-in subfolder** — does not take over site serving
- **Backup on open** — every file is copied to `sneeze/data/backups/` before editing
- **Basic View** — edit `<section>` blocks with contenteditable toolbar
- **Advanced View** — CodeMirror segments for precise HTML editing
- **Preview** — injects correct `<base href>` so assets load from live URLs
- **Media library** — uploads to `{site}/uploads/` with relative URL insertion
- **Users** — admin and editor roles
- **Design** — admin UI styled to match vestbykorps.no tokens

## Security

- Session cookies scoped to `/sneeze/`
- CSRF protection on mutating requests
- Path traversal blocked on all file operations
- `data/`, `lib/`, `views/` blocked via `.htaccess`

## Removing Sneeze

Delete the `sneeze/` folder. Your site files (including `uploads/`) remain. Backups inside `sneeze/data/backups/` are removed with the folder.

## Apache configuration

If rewrite rules do not work, ensure `AllowOverride All` is set for the directory. The included `.htaccess` routes all requests through `index.php`.

## nginx snippet

```nginx
location /sneeze/ {
    try_files $uri $uri/ /sneeze/index.php?$query_string;
}
```
