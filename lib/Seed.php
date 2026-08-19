<?php

declare(strict_types=1);

class Seed
{
    public static function run(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');

        $pdo->prepare('INSERT INTO design_tokens (id, tokens_json) VALUES (1, ?)')->execute([
            Json::encode(self::tokens()),
        ]);

        $pdo->prepare(
            'INSERT INTO stylesheets (name, css, sort_order, enabled) VALUES (?, ?, 0, 1)'
        )->execute(['Theme', self::themeCss()]);

        $pdo->prepare(
            'INSERT INTO scripts (name, js, placement, sort_order, enabled) VALUES (?, ?, ?, 0, 1)'
        )->execute(['Site', self::themeJs(), 'footer']);

        $pdo->prepare(
            'INSERT INTO templates (name, slug, html, css, js, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'Base',
            'base',
            self::baseTemplate(),
            self::templateCss(),
            '',
            $now,
            $now,
        ]);
        $templateId = (int) $pdo->lastInsertId();

        foreach (self::blockTypes() as $type) {
            $pdo->prepare(
                'INSERT INTO block_types (name, slug, category, icon, schema_json, html, css, js, is_system, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([
                $type['name'],
                $type['slug'],
                $type['category'],
                $type['icon'],
                Json::encode($type['schema']),
                $type['html'],
                $type['css'],
                $type['js'] ?? '',
                $now,
                $now,
            ]);
        }

        $types = [];
        foreach ($pdo->query('SELECT id, slug FROM block_types')->fetchAll() as $row) {
            $types[$row['slug']] = (int) $row['id'];
        }

        $homeId = self::insertPage($pdo, [
            'title' => 'Home',
            'slug' => 'index',
            'template_id' => $templateId,
            'seo_description' => 'A static site designed in Sneeze.',
            'status' => 'draft',
        ], $now);

        $aboutId = self::insertPage($pdo, [
            'title' => 'About',
            'slug' => 'about',
            'template_id' => $templateId,
            'seo_description' => 'About this site.',
            'status' => 'draft',
        ], $now);

        self::insertBlock($pdo, $homeId, $types['hero'], null, 'content', 0, [
            'title' => 'Design in the studio. Publish a file.',
            'subtitle' => 'Sneeze stores templates, blocks, CSS and JS in a database, then merges them into a single HTML file when you publish.',
            'button_label' => 'Read the idea',
            'button_url' => 'about.html',
            'image' => '',
        ]);

        $columnsId = self::insertBlock($pdo, $homeId, $types['columns'], null, 'content', 1, [
            'heading' => 'How it works',
        ]);
        self::insertBlock($pdo, $homeId, $types['richtext'], $columnsId, 'left', 0, [
            'body' => '<h3>Blocks &amp; templates</h3><p>Build pages from reusable blocks inside a layout template. Nested slots let you place content in columns without touching the published file.</p>',
        ]);
        self::insertBlock($pdo, $homeId, $types['richtext'], $columnsId, 'right', 0, [
            'body' => '<h3>One HTML file</h3><p>On publish, styles and scripts are inlined. Visitors get a static page — no PHP, no CMS round-trip, no client-side hydration required.</p>',
        ]);

        self::insertBlock($pdo, $homeId, $types['quote'], null, 'content', 2, [
            'quote' => 'The live site is just files. The studio is where the site is designed.',
            'cite' => 'Sneeze',
        ]);

        self::insertBlock($pdo, $homeId, $types['cta'], null, 'content', 3, [
            'heading' => 'Ready when you are',
            'text' => 'Save drafts as you work. Publish writes index.html into your site folder.',
            'button_label' => 'Open about',
            'button_url' => 'about.html',
        ]);

        self::insertBlock($pdo, $aboutId, $types['heading'], null, 'content', 0, [
            'text' => 'A drop-in studio for static sites',
            'level' => 'h1',
        ]);
        self::insertBlock($pdo, $aboutId, $types['richtext'], null, 'content', 1, [
            'body' => '<p>Copy the Sneeze folder into an existing site. Design in the admin. When you publish, Sneeze merges templates, blocks, CSS and JavaScript into ordinary HTML at your site root.</p><p>Headless CMS content is fetched from the admin and cached. Page views never call the CMS — they only receive the last published file.</p>',
        ]);
        self::insertBlock($pdo, $aboutId, $types['html'], null, 'content', 2, [
            'html' => '<div class="wrap"><p class="lede">This block is raw HTML. Edit it in the Code tab.</p></div>',
        ]);
    }

    private static function insertPage(PDO $pdo, array $page, string $now): int
    {
        $pdo->prepare(
            'INSERT INTO pages (title, slug, template_id, seo_description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $page['title'],
            $page['slug'],
            $page['template_id'],
            $page['seo_description'],
            $page['status'],
            $now,
            $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function insertBlock(
        PDO $pdo,
        int $pageId,
        int $typeId,
        ?int $parentId,
        string $slot,
        int $order,
        array $data
    ): int {
        $pdo->prepare(
            'INSERT INTO page_blocks (page_id, block_type_id, parent_id, slot, sort_order, data_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pageId, $typeId, $parentId, $slot, $order, Json::encode($data)]);
        return (int) $pdo->lastInsertId();
    }

    public static function tokens(): array
    {
        return [
            'color' => [
                'brand' => '#d04c4c',
                'brand-dark' => '#b83f3f',
                'navy' => '#2a2438',
                'cream' => '#faf7f5',
                'white' => '#ffffff',
                'text' => '#2c2438',
                'muted' => '#5c5468',
                'border' => '#e8ddd9',
                'gold' => '#c9a227',
            ],
            'font' => [
                'display' => 'Georgia, "Times New Roman", serif',
                'body' => 'system-ui, "Segoe UI", sans-serif',
            ],
            'width' => [
                'wrap' => '1100px',
            ],
            'radius' => [
                'md' => '12px',
            ],
        ];
    }

    public static function themeCss(): string
    {
        return <<<'CSS'
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0;
  font-family: var(--font-body);
  font-size: 1.0625rem;
  line-height: 1.65;
  color: var(--color-text);
  background: var(--color-cream);
}
img { max-width: 100%; height: auto; display: block; }
a { color: var(--color-brand); text-decoration: none; }
a:hover { color: var(--color-brand-dark); }
h1, h2, h3, h4 {
  font-family: var(--font-display);
  line-height: 1.2;
  color: var(--color-navy);
  font-weight: 700;
}
h1 { font-size: clamp(2rem, 5vw, 3.25rem); margin: 0 0 0.6rem; }
h2 { font-size: clamp(1.5rem, 3vw, 2rem); margin: 0 0 0.75rem; }
h3 { font-size: 1.25rem; margin: 0 0 0.5rem; }
p { margin: 0 0 1rem; }
.wrap { width: min(var(--width-wrap), 92%); margin-inline: auto; }
.lede { font-size: 1.2rem; color: var(--color-muted); }
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem 1.4rem;
  border-radius: 999px;
  background: var(--color-brand);
  color: var(--color-white);
  font-weight: 600;
  border: 0;
}
.btn:hover { background: var(--color-brand-dark); color: var(--color-white); }
.prose :last-child { margin-bottom: 0; }
CSS;
    }

    public static function themeJs(): string
    {
        return "document.documentElement.classList.add('is-ready');\n";
    }

    public static function templateCss(): string
    {
        return <<<'CSS'
.site-header {
  background: var(--color-navy);
  color: var(--color-white);
}
.site-header .wrap {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 72px;
}
.site-logo {
  font-family: var(--font-display);
  font-size: 1.35rem;
  color: var(--color-white);
}
.site-nav { display: flex; gap: 1.25rem; flex-wrap: wrap; }
.site-nav a { color: rgba(255,255,255,.82); font-weight: 600; }
.site-nav a.is-current, .site-nav a:hover { color: var(--color-white); }
.site-main { padding: 0 0 4rem; }
.site-footer {
  border-top: 1px solid var(--color-border);
  padding: 2rem 0;
  color: var(--color-muted);
  font-size: 0.9rem;
}
CSS;
    }

    public static function baseTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{page.seo_title}}</title>
  <meta name="description" content="{{page.seo_description}}">
  <!-- sneeze:css -->
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a class="site-logo" href="{{site.home_url}}">{{site.title}}</a>
      <nav class="site-nav">
        {{#each pages}}
        <a href="{{url}}" class="{{#if current}}is-current{{/if}}">{{title}}</a>
        {{/each}}
      </nav>
    </div>
  </header>
  <main class="site-main">
    {{slot:content}}
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p>© {{site.title}}</p>
    </div>
  </footer>
  <!-- sneeze:js -->
</body>
</html>
HTML;
    }

    public static function blockTypes(): array
    {
        return [
            [
                'name' => 'Hero',
                'slug' => 'hero',
                'category' => 'sections',
                'icon' => '▣',
                'schema' => [
                    'fields' => [
                        ['name' => 'title', 'label' => 'Title', 'type' => 'text'],
                        ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'textarea'],
                        ['name' => 'button_label', 'label' => 'Button label', 'type' => 'text'],
                        ['name' => 'button_url', 'label' => 'Button URL', 'type' => 'url'],
                        ['name' => 'image', 'label' => 'Background image', 'type' => 'image'],
                    ],
                    'slots' => [],
                ],
                'html' => <<<'HTML'
<section class="hero">
  {{#if image}}<div class="hero__media"><img src="{{image}}" alt=""></div>{{/if}}
  <div class="hero__inner wrap">
    <h1>{{title}}</h1>
    {{#if subtitle}}<p class="lede">{{subtitle}}</p>{{/if}}
    {{#if button_label}}<a class="btn" href="{{button_url}}">{{button_label}}</a>{{/if}}
  </div>
</section>
HTML,
                'css' => <<<'CSS'
.hero { position: relative; padding: 4.5rem 0 4rem; background: var(--color-white); overflow: hidden; }
.hero__media { position: absolute; inset: 0; }
.hero__media img { width: 100%; height: 100%; object-fit: cover; opacity: .28; }
.hero__inner { position: relative; max-width: 40rem; margin-left: auto; margin-right: auto; text-align: center; }
.hero .btn { margin-top: .5rem; }
CSS,
            ],
            [
                'name' => 'Heading',
                'slug' => 'heading',
                'category' => 'content',
                'icon' => 'H',
                'schema' => [
                    'fields' => [
                        ['name' => 'text', 'label' => 'Heading', 'type' => 'text'],
                        ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => ['h1', 'h2', 'h3']],
                    ],
                    'slots' => [],
                ],
                'html' => '<div class="wrap heading-block">{{{heading_html}}}</div>',
                'css' => '.heading-block { padding: 2.5rem 0 0; }',
            ],
            [
                'name' => 'Rich text',
                'slug' => 'richtext',
                'category' => 'content',
                'icon' => '¶',
                'schema' => [
                    'fields' => [
                        ['name' => 'body', 'label' => 'Body', 'type' => 'richtext'],
                    ],
                    'slots' => [],
                ],
                'html' => '<div class="prose wrap richtext-block">{{{body}}}</div>',
                'css' => '.richtext-block { padding: 1.25rem 0 2rem; }',
            ],
            [
                'name' => 'Image',
                'slug' => 'image',
                'category' => 'media',
                'icon' => '▣',
                'schema' => [
                    'fields' => [
                        ['name' => 'src', 'label' => 'Image', 'type' => 'image'],
                        ['name' => 'alt', 'label' => 'Alt text', 'type' => 'text'],
                        ['name' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                    ],
                    'slots' => [],
                ],
                'html' => <<<'HTML'
<figure class="figure wrap">
  {{#if src}}<img src="{{src}}" alt="{{alt}}">{{/if}}
  {{#if caption}}<figcaption>{{caption}}</figcaption>{{/if}}
</figure>
HTML,
                'css' => <<<'CSS'
.figure { padding: 1rem 0 2rem; }
.figure img { border-radius: var(--radius-md); }
.figure figcaption { margin-top: .5rem; color: var(--color-muted); font-size: .9rem; }
CSS,
            ],
            [
                'name' => 'Columns',
                'slug' => 'columns',
                'category' => 'layout',
                'icon' => '▥',
                'schema' => [
                    'fields' => [
                        ['name' => 'heading', 'label' => 'Optional heading', 'type' => 'text'],
                    ],
                    'slots' => ['left', 'right'],
                ],
                'html' => <<<'HTML'
<section class="columns wrap">
  {{#if heading}}<h2>{{heading}}</h2>{{/if}}
  <div class="columns__grid">
    <div class="columns__col">{{slot:left}}</div>
    <div class="columns__col">{{slot:right}}</div>
  </div>
</section>
HTML,
                'css' => <<<'CSS'
.columns { padding: 2rem 0; }
.columns__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
@media (max-width: 800px) { .columns__grid { grid-template-columns: 1fr; } }
.columns__col { background: var(--color-white); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 1.25rem 1.4rem; }
CSS,
            ],
            [
                'name' => 'Quote',
                'slug' => 'quote',
                'category' => 'content',
                'icon' => '“',
                'schema' => [
                    'fields' => [
                        ['name' => 'quote', 'label' => 'Quote', 'type' => 'textarea'],
                        ['name' => 'cite', 'label' => 'Citation', 'type' => 'text'],
                    ],
                    'slots' => [],
                ],
                'html' => <<<'HTML'
<blockquote class="quote wrap">
  <p>{{quote}}</p>
  {{#if cite}}<cite>— {{cite}}</cite>{{/if}}
</blockquote>
HTML,
                'css' => <<<'CSS'
.quote { padding: 2rem 0; border: 0; margin: 0 auto; max-width: 40rem; text-align: center; }
.quote p { font-family: var(--font-display); font-size: 1.5rem; color: var(--color-navy); }
.quote cite { color: var(--color-muted); font-style: normal; }
CSS,
            ],
            [
                'name' => 'Call to action',
                'slug' => 'cta',
                'category' => 'sections',
                'icon' => '→',
                'schema' => [
                    'fields' => [
                        ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                        ['name' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                        ['name' => 'button_label', 'label' => 'Button label', 'type' => 'text'],
                        ['name' => 'button_url', 'label' => 'Button URL', 'type' => 'url'],
                    ],
                    'slots' => [],
                ],
                'html' => <<<'HTML'
<section class="cta">
  <div class="wrap cta__inner">
    <h2>{{heading}}</h2>
    {{#if text}}<p>{{text}}</p>{{/if}}
    {{#if button_label}}<a class="btn" href="{{button_url}}">{{button_label}}</a>{{/if}}
  </div>
</section>
HTML,
                'css' => <<<'CSS'
.cta { background: var(--color-navy); color: var(--color-white); padding: 3.5rem 0; text-align: center; }
.cta h2 { color: var(--color-white); }
.cta p { color: rgba(255,255,255,.8); }
.cta .btn { background: var(--color-brand); }
CSS,
            ],
            [
                'name' => 'Spacer',
                'slug' => 'spacer',
                'category' => 'layout',
                'icon' => '↕',
                'schema' => [
                    'fields' => [
                        ['name' => 'height', 'label' => 'Height (px)', 'type' => 'number'],
                    ],
                    'slots' => [],
                ],
                'html' => '<div class="spacer" style="height: {{height}}px" aria-hidden="true"></div>',
                'css' => '.spacer { display: block; }',
            ],
            [
                'name' => 'HTML',
                'slug' => 'html',
                'category' => 'advanced',
                'icon' => '</>',
                'schema' => [
                    'fields' => [
                        ['name' => 'html', 'label' => 'HTML', 'type' => 'code', 'mode' => 'html'],
                    ],
                    'slots' => [],
                ],
                'html' => '{{{html}}}',
                'css' => '',
            ],
        ];
    }
}
