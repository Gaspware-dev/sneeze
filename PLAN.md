# Sneeze Rework — Architecture Plan

Sneeze becomes a **drop-in static site studio**: the database is the source of truth. Editors design templates and blocks, write content, and **publish** compiled output as ordinary HTML files. The live site stays a flat-file website with no runtime PHP.

This document is the plan for the `/rework` branch. It replaces the previous “edit existing HTML files on disk” model.

---

## Product shift

| Before | After |
|---|---|
| HTML files on disk are the source of truth | SQLite is the source of truth |
| Open a file, edit sections, save back | Compose a page from **blocks** inside a **template** |
| CSS/JS live only in the site’s files | CSS, JS, and design tokens live in the database |
| Preview injects `<base href>` into existing HTML | Preview compiles the same pipeline as publish |
| No notion of draft vs live | **Save draft** to DB; **Publish** writes one HTML file per page |

Drop-in install is unchanged: copy the `sneeze/` folder next to the site, run setup, point at a writable site root. Published files land there (`index.html`, `about.html`, …). Delete `sneeze/` and the last published HTML remains.

---

## Studio UX

```
┌──────────────────────────────────────────────────────────────────┐
│ Brand   Pages ▾  [Home]     draft · saved    Desktop Tablet Phone │
│         Library  Design  Media  CMS  Users  Settings     Publish │
├────────────────────────────────┬─────────────────────────────────┤
│                                │  Blocks  │  Write  │  Code      │
│                                │─────────────────────────────────│
│      LIVE PREVIEW (iframe)     │  Page outline + block palette   │
│      Click a block to select   │  or field editor / code editors │
│                                │                                 │
└────────────────────────────────┴─────────────────────────────────┘
```

- **Left:** live preview of the compiled page (same compiler as publish, plus selection chrome).
- **Right:** working surface
  - **Blocks** — outline, reorder, add from palette, nested slots (e.g. columns).
  - **Write** — schema-driven fields for the selected block (text, rich text, image, URL, …).
  - **Code** — HTML / CSS / JS for the selected block type, or the page template / page CSS.
- **Library** — create and edit templates and block types (stored in DB); **import an existing HTML file** into a template + HTML block.
- **Design** — tokens (compiled to `:root` CSS variables), TypeScript/Tailwind toolchain (`tailwind.config.ts`), global stylesheets, global scripts.
- **Media** — studio library; files stay in Sneeze until publish.
- **CMS** — headless sources fetched **in the admin**, cached in SQLite, never at visitor request time.

---

## Data model

All creative artifacts live in SQLite (`data/sneeze.db`).

- **templates** — full HTML document with `{{slot:content}}`, plus template CSS/JS.
- **block_types** — reusable definitions: JSON field schema, HTML snippet, optional CSS/JS, slots for children.
- **pages** — title, slug, template, SEO, page-level CSS/JS, draft/published.
- **page_blocks** — instances on a page: type, parent, slot, sort order, JSON field data; optional CMS binding.
- **stylesheets** / **scripts** — global assets concatenated at compile time. Scripts compile as TypeScript.
- **design_tokens** — JSON map flattened to CSS custom properties.
- **toolchain** — TypeScript and Tailwind toggles plus stored `tailwind.config.ts` (theme colors also come from design tokens).
- **page_revisions** — HTML snapshot on each publish.
- **cms_sources** / **cms_entries** — adapter config + cached payloads.
- Existing **users**, **media**, **audit_log**, **login_attempts**.

Filesystem: published HTML lands in the site root. Binary media is uploaded into **`sneeze/data/uploads`** (studio-only). On publish, files actually used on the page are copied into the configurable site **asset folder** (`uploads/` by default). Metadata stays in SQLite.

---

## Compile → single HTML file

On preview and on publish, `Compiler` builds one document:

1. Resolve the page’s template and block tree (nested by `parent_id` + `slot`).
2. Render each block: interpolate fields into the type’s HTML; replace `{{slot:name}}` with children.
3. Concatenate CSS in order: design tokens → global stylesheets → template CSS → CSS of used block types → page CSS.
4. Concatenate JS in order: global head scripts → global footer scripts → template JS → JS of used block types → page JS.
5. **TypeScript:** compile concatenated JS (`esbuild` when present, otherwise a built-in type stripper).
6. **Tailwind:** scan the rendered HTML (and `@apply` in CSS) and emit used utilities. Theme colors/fonts come from design tokens and `tailwind.config.ts`. Uses the Tailwind CLI when installed; otherwise a built-in engine.
7. Inject **one** minified `<style>` at the end of `<head>` (render-blocking CSS is correct for inlined critical styles; unused block-type CSS is omitted).
8. Inject **one** minified `<script>` immediately before `</body>`. Inline scripts cannot use `defer`/`async` (invalid without `src`), so they never go in `<head>` — including scripts marked “head”, which still run first in the footer bundle.
9. Normalize markup for [the W3C HTML checker](https://validator.w3.org/): strip empty `class`/`id`/`style`, ensure `alt` on images, require `<!DOCTYPE html>`, drop empty style/script tags.
10. **Publish** writes `{slug}.html` (`index` → `index.html`) into the configured site root, atomically, with a backup of the previous file.

Preview skips minification so the iframe stays readable; published files are minified.

Template language (intentionally small, no Composer):

- `{{path.to.value}}` escaped text
- `{{{html}}}` raw HTML (rich text)
- `{{#if key}}…{{/if}}` / `{{#unless key}}` / `{{#each key}}`
- `{{slot:name}}` child blocks

---

## Headless CMS (cached)

Visitors never talk to a CMS. Editors configure a source in the admin and click **Sync**:

1. Adapter fetches remote JSON (HTTP JSON in this phase).
2. Entries are stored in `cms_entries` (cache).
3. Page blocks can bind `cms_source_id` + `cms_entry_key`; on sync, mapped fields overwrite that block’s `data_json`.
4. The page is still a **draft** until someone publishes.

Later adapters (Contentful, Sanity, WordPress REST) implement the same `CmsAdapter` contract: `fetch(config) → entries[]`. Mapping stays in Sneeze so the compile pipeline never changes.

---

## Security (unchanged principles)

- Session cookie scoped to `/sneeze/`
- CSRF on mutating requests
- Path traversal blocked when writing published files / uploads
- `data/`, `lib/`, `views/` not web-accessible
- Login lockout
- Editors can work on pages; admins own templates, block types, design, CMS, users, settings

---

## Phases

**This branch (foundation)**

- Schema, seed starter site, compiler, publisher
- Split studio with live preview
- Block / template library in DB
- Design tokens + global CSS/JS
- Import an existing HTML file into a template + HTML block
- Studio vs published media folders (draft uploads stay in `sneeze/data/uploads` until publish)
- HTTP JSON CMS sync + cache
- Setup writes to a **writable** folder (HTML files optional; they are generated)

**Next**

- Drag from preview, inline text editing in the iframe
- Revision browser / rollback
- More CMS adapters and field mapping UI polish

**Later**

- Multi-language, scheduled publish, collaborative presence
- Optional per-page “collection” pages generated from cached CMS lists at publish time (still static files)

---

## Non-goals

- No PHP on the public site at request time
- No Node build required to run Sneeze (vanilla JS + CodeMirror CDN)
- No replacing the host web server; Sneeze only writes files
