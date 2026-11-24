<?php

declare(strict_types=1);

$failed = 0;
$passed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $failed, $passed;
    if ($actual !== $expected) {
        $failed++;
        fwrite(STDERR, "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        return;
    }
    $passed++;
    echo "ok  {$label}\n";
}

function expectContains(string $label, string $haystack, string $needle): void
{
    global $failed, $passed;
    if (!str_contains($haystack, $needle)) {
        $failed++;
        fwrite(STDERR, "FAIL {$label}\n  missing: {$needle}\n");
        return;
    }
    $passed++;
    echo "ok  {$label}\n";
}

$installDir = sys_get_temp_dir() . '/sneeze-test-' . bin2hex(random_bytes(4));
mkdir($installDir, 0755, true);
mkdir($installDir . '/lib', 0755, true);

spl_autoload_register(function (string $class): void {
    $file = dirname(__DIR__) . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require dirname(__DIR__) . '/lib/Interpolator.php';
require dirname(__DIR__) . '/lib/Minifier.php';

$i = new Interpolator();
expect('escaped', $i->render('Hi {{name}}', ['name' => '<b>x</b>']), 'Hi &lt;b&gt;x&lt;/b&gt;');
expect('raw', $i->render('{{{html}}}', ['html' => '<b>x</b>']), '<b>x</b>');
expect('if true', $i->render('{{#if show}}YES{{/if}}', ['show' => true]), 'YES');
expect('if false', $i->render('{{#if show}}YES{{/if}}', ['show' => '']), '');
expect('unless', $i->render('{{#unless show}}NO{{/unless}}', ['show' => '']), 'NO');
expect(
    'each',
    $i->render('{{#each pages}}[{{title}}]{{/each}}', ['pages' => [['title' => 'A'], ['title' => 'B']]]),
    '[A][B]'
);
expect('slot', $i->render('X{{slot:content}}Y', ['_slots' => ['content' => 'Z']]), 'XZY');
expect('nested if in each', $i->render(
    '{{#each pages}}{{#if current}}*{{/if}}{{title}}{{/each}}',
    ['pages' => [['title' => 'Home', 'current' => true], ['title' => 'About', 'current' => false]]]
), '*HomeAbout');

$css = Minifier::css("/* x */\n.prose :last-child { margin-bottom: 0; }\nh1, h2 { color: red; }");
expect('css keeps descendant pseudo', str_contains($css, '.prose :last-child'), true);
expect('css minifies commas', str_contains($css, 'h1,h2'), true);
expect('css strips comments', str_contains($css, '/*'), false);
$js = Minifier::js("/* a */\nfunction hi ( name ) {\n  return name;\n}\n");
expect('js minify', $js, 'function hi(name){return name;}');

$ts = TypeScriptCompiler::stripTypes('const greet = (name: string): string => `hi ${name}`;');
expectContains('ts strips param types', $ts, '(name)');
expect('ts strips annotations', str_contains($ts, ': string'), false);
$tw = TailwindCompiler::viaPhp('', '<div class="flex items-center p-4 bg-brand md:p-8 hover:opacity-80"></div>', [
    'tokens' => ['color' => ['brand' => '#d04c4c']],
]);
expectContains('tw flex', $tw, 'display:flex');
expectContains('tw padding', $tw, 'padding:1rem');
expectContains('tw token color', $tw, '--color-brand');
expectContains('tw breakpoint', $tw, '@media (min-width:768px)');
expectContains('tw hover', $tw, ':hover');
$apply = TailwindCompiler::viaPhp('.btn{@apply flex items-center;}', '', [
    'tokens' => ['color' => ['brand' => '#d04c4c']],
]);
expectContains('tw apply', $apply, 'display:flex');
$fromConfig = TailwindCompiler::viaPhp('', '<div class="bg-extra"></div>', [
    'tokens' => ['color' => ['brand' => '#d04c4c']],
    'tailwind_config_ts' => 'export default { theme: { extend: { colors: { extra: "#111111" } } } };',
]);
expectContains('tw config.ts color', $fromConfig, '#111111');
$cfgJs = TailwindCompiler::configToCjs(ToolchainRepository::defaultConfigTs(['color' => ['brand' => '#d04c4c']]));
expectContains('tw config to cjs', $cfgJs, 'module.exports');
expect('tw config no import type', str_contains($cfgJs, 'import type'), false);
expect('tw config no satisfies', str_contains($cfgJs, 'satisfies'), false);

expect('asset folder sanitized', Config::sanitizeUploadDir('assets/img', false), 'assets/img');
expect('asset folder rejects traversal', Config::sanitizeUploadDir('../etc', false), 'uploads');
expect('inline cap', Config::normalizeInlineImageMaxBytes(9000000), 524288);
expect('inline disabled floor', Config::normalizeInlineImageMaxBytes(-4), 0);

$doc = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Landing</title>
  <meta name="description" content="Old site">
  <style>h1 { color: red; }</style>
</head>
<body>
  <header>Nav</header>
  <main class="content"><h1>Hello imported</h1></main>
  <footer>Foot</footer>
</body>
</html>
HTML;
$parsed = (new HtmlImporter())->parse($doc);
expect('import is document', $parsed['is_document'], true);
expect('import title', $parsed['title'], 'Landing');
expect('import description', $parsed['description'], 'Old site');
expectContains('import slot', $parsed['template_html'], '{{slot:content}}');
expectContains('import title token', $parsed['template_html'], '{{page.seo_title}}');
expectContains('import content', $parsed['content'], 'Hello imported');
expect('import content not in chrome', str_contains($parsed['template_html'], 'Hello imported'), false);
expectContains('import css extracted', $parsed['css'], 'color: red');
$frag = (new HtmlImporter())->parse('<section class="hero">Fragment</section>');
expect('fragment not document', $frag['is_document'], false);
expectContains('fragment body', $frag['content'], 'Fragment');

Config::init($installDir);
Database::connect(Config::dataDir());

$home = PageRepository::all()[0] ?? null;
expect('seeded home', $home['slug'] ?? null, 'index');

$html = (new Compiler())->compilePage((int) $home['id'], false);
expectContains('doctype', $html, '<!DOCTYPE html>');
expectContains('hero title', $html, 'Design in the studio. Publish a file.');
expectContains('inlined css', $html, ':root');
expectContains('inlined css var', $html, '--color-brand');
expectContains('nav about', $html, 'about.html');
expectContains('nav home label', $html, '>Home</a>');
expectContains('nav about label', $html, '>About</a>');
expectContains('aria current', $html, 'aria-current="page"');
expectContains('style in head', $html, '</style>' . "\n</head>");
expectContains('minified css', $html, ':root{');
expect('no empty class', str_contains($html, 'class=""'), false);
expect('no unused js tag', str_contains($html, '<script'), false);
expect('no css comments', str_contains($html, '/* Theme'), false);
expectContains('quote figure', $html, '<figure class="quote wrap">');
if (str_contains($html, 'sneeze-block')) {
    $failed++;
    fwrite(STDERR, "FAIL publish html should not include preview chrome\n");
} else {
    $passed++;
    echo "ok  no preview chrome on publish\n";
}

$preview = (new Compiler())->compilePage((int) $home['id'], true);
expectContains('preview chrome', $preview, 'data-sneeze-block');

$siteRoot = $installDir . '/site';
mkdir($siteRoot, 0755, true);
Config::set('site_root', $siteRoot);
Config::set('setup_complete', true);
Config::save();

$published = (new Publisher())->publishPage((int) $home['id']);
expect('publish path', $published['path'], 'index.html');
expect('wrote file', is_file($siteRoot . '/index.html'), true);
$written = file_get_contents($siteRoot . '/index.html') ?: '';
expectContains('written hero', $written, 'How it works');
expectContains('published minified', $written, ':root{');
expect('published no script', str_contains($written, '<script'), false);

$withJs = (new Compiler())->compilePage((int) $home['id'], false, [
    'custom_js' => "const greet = (name: string): string => `ok`;\nconsole.log(greet('a'));\n",
]);
expectContains('js before body end', $withJs, "</script>\n</body>");
preg_match('/<head[\s\S]*?<\/head>/i', $withJs, $headMatch);
expect('js not in head', str_contains(strtolower($headMatch[0] ?? ''), '<script'), false);
expect('ts compiled away', str_contains($withJs, ': string'), false);

$tpl = TemplateRepository::get((int) $home['template_id']);
$tpl['html'] = str_replace('<main class="site-main">', '<main class="site-main flex p-4 bg-cream">', $tpl['html']);
$withTw = (new Compiler())->compilePage((int) $home['id'], false, ['template' => $tpl]);
expectContains('tailwind in publish', $withTw, 'display:flex');

$imported = (new HtmlImporter())->import($doc, ['name' => 'Landing']);
expect('import page created', $imported['page']['title'] ?? null, 'Landing');
expect('import slug not index', ($imported['page']['slug'] ?? 'index') !== 'index', true);
expectContains('import saved content', json_encode($imported['page']['blocks'] ?? []), 'Hello imported');
expectContains('import template slot', $imported['template']['html'] ?? '', '{{slot:content}}');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==');
$pngPath = $installDir . '/dot.png';
file_put_contents($pngPath, $png);
Config::set('upload_dir', 'assets');
Config::set('inline_image_max_bytes', 8192);
expect('inline threshold loaded', Config::inlineImageMaxBytes(), 8192);
$media = (new MediaStore())->ingest($pngPath, 'dot.png', 0);
expect('media draft exists', is_file(MediaStore::draftAbs($media['storage_key'])), true);
expect('media not public yet', is_file(MediaStore::publishedAbs($media['storage_key'])), false);
expectContains('media public path', $media['path'], 'assets/');

$largeBytes = $png . str_repeat('Z', 9000);
$large = addMediaFile($largeBytes, 'large.png', 'image/png');
expect('large image exceeds threshold', $large['size'] > 8192, true);
$pdfBytes = "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$pdf = addMediaFile($pdfBytes, 'note.pdf', 'application/pdf');
expect('pdf is under image threshold', strlen($pdfBytes) < 8192, true);

$mediaTpl = TemplateRepository::get((int) $home['template_id']);
$mediaTpl['html'] = str_replace(
    '{{slot:content}}',
    '<img src="' . $media['path'] . '" alt="dot">'
    . '<img src="' . $large['path'] . '" alt="large">'
    . '<a href="' . $pdf['path'] . '">notes</a>'
    . '{{slot:content}}',
    $mediaTpl['html']
);
$exportDraft = [
    'template' => $mediaTpl,
    'custom_css' => '.export-marker{color:#123456}body{background-image:url(' . $media['path'] . ')}',
    'custom_js' => "function ping(){return 'pong';}\n",
];
$mediaPreview = (new Compiler())->compilePage((int) $home['id'], true, $exportDraft);
expectContains('preview uses studio media', $mediaPreview, 'api/media/file/' . $media['id']);
expectContains('preview large uses studio media', $mediaPreview, 'api/media/file/' . $large['id']);
expect('preview does not inline', str_contains($mediaPreview, 'data:image/'), false);

$mediaPublish = (new Compiler())->compilePage((int) $home['id'], false, $exportDraft);
$smallUri = 'data:image/png;base64,' . base64_encode($png);
expectContains('small image inlined', $mediaPublish, $smallUri);
expect('small image omitted from asset folder', is_file(MediaStore::publishedAbs($media['storage_key'])), false);
expect('small path not left in html', str_contains($mediaPublish, $media['storage_key']), false);
expectContains('css url inlined', $mediaPublish, 'url(' . $smallUri . ')');
expectContains('large image stays a file', $mediaPublish, $large['path']);
expect('large image copied', is_file(MediaStore::publishedAbs($large['storage_key'])), true);
expect('large image not inlined', str_contains($mediaPublish, 'data:image/png;base64,' . base64_encode($largeBytes)), false);
expectContains('pdf stays a file', $mediaPublish, $pdf['path']);
expect('pdf copied', is_file(MediaStore::publishedAbs($pdf['storage_key'])), true);
expect('pdf not inlined', str_contains($mediaPublish, 'data:application/pdf'), false);
expectContains('export css inline', $mediaPublish, '.export-marker{color:#123456}');
expectContains('export js inline', $mediaPublish, "function ping(){return'pong';}");
expectContains('style closed in head', $mediaPublish, "</style>\n</head>");
expectContains('script closed in body', $mediaPublish, "</script>\n</body>");
expect('single style tag', substr_count(strtolower($mediaPublish), '<style>'), 1);
expect('single script tag', substr_count(strtolower($mediaPublish), '<script>'), 1);
expect('no stylesheet link', preg_match('/<link\b[^>]*stylesheet/i', $mediaPublish) === 1, false);
expect('no external script', preg_match('/<script\b[^>]*\bsrc=/i', $mediaPublish) === 1, false);
expect('publish has no studio url', str_contains($mediaPublish, 'api/media/file/'), false);
expect('publish has no preview chrome', str_contains($mediaPublish, 'sneeze-block'), false);

Config::set('inline_image_max_bytes', strlen($png));
$atThreshold = (new Compiler())->compilePage((int) $home['id'], false, $exportDraft);
expectContains('exact threshold inlines', $atThreshold, $smallUri);

Config::set('inline_image_max_bytes', max(0, strlen($png) - 1));
$below = (new Compiler())->compilePage((int) $home['id'], false, $exportDraft);
expectContains('below threshold stays a file', $below, $media['path']);
expect('below threshold copied', is_file(MediaStore::publishedAbs($media['storage_key'])), true);
expect('below threshold not inlined', str_contains($below, $smallUri), false);

Config::set('inline_image_max_bytes', 0);
$disabled = (new Compiler())->compilePage((int) $home['id'], false, $exportDraft);
expect('zero disables inlining', str_contains($disabled, 'data:image/'), false);
expectContains('disabled still emits css', $disabled, '.export-marker{color:#123456}');
expectContains('disabled still emits js', $disabled, "function ping(){return'pong';}");

Config::set('site_title', 'SQLite Settings');
Config::set('inline_image_max_bytes', 4096);
Config::save();
$stored = Database::pdo()->query("SELECT value FROM settings WHERE key = 'inline_image_max_bytes'")->fetchColumn();
expect('settings live in sqlite', json_decode((string) $stored, true), 4096);
$titleRow = Database::pdo()->query("SELECT value FROM settings WHERE key = 'site_title'")->fetchColumn();
expect('site title stored in sqlite', json_decode((string) $titleRow, true), 'SQLite Settings');
Database::pdo()->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([json_encode('From SQLite'), 'site_title']);
Config::hydrateFromDatabase(Database::pdo());
expect('sqlite settings are authoritative', Config::get('site_title'), 'From SQLite');

$errors = w3cErrors($written);
expect('w3c errors', $errors, []);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

function addMediaFile(string $bytes, string $filename, string $mime): array
{
    $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
    $key = '2026/' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = MediaStore::draftAbs($key);
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0750, true);
    }
    file_put_contents($dest, $bytes);
    Database::pdo()->prepare(
        'INSERT INTO media (filename, path, mime, size, uploaded_by) VALUES (?, ?, ?, ?, NULL)'
    )->execute([$filename, $key, $mime, strlen($bytes)]);

    return [
        'id' => (int) Database::pdo()->lastInsertId(),
        'path' => Config::uploadDir() . '/' . $key,
        'storage_key' => $key,
        'mime' => $mime,
        'size' => strlen($bytes),
    ];
}

function w3cErrors(string $html): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/html; charset=utf-8\r\nUser-Agent: SneezeTests/1.0\r\n",
            'content' => $html,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('https://validator.w3.org/nu/?out=json', false, $context);
    if ($raw === false) {
        return ['validator unreachable'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['validator returned non-json'];
    }
    $errors = [];
    foreach ($data['messages'] ?? [] as $message) {
        if (($message['type'] ?? '') === 'error') {
            $errors[] = ($message['message'] ?? 'error') . ' (line ' . ($message['lastLine'] ?? '?') . ')';
        }
    }
    return $errors;
}
