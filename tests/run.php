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
$media = (new MediaStore())->ingest($pngPath, 'dot.png', 0);
expect('media draft exists', is_file(MediaStore::draftAbs($media['storage_key'])), true);
expect('media not public yet', is_file(MediaStore::publishedAbs($media['storage_key'])), false);
expectContains('media public path', $media['path'], 'assets/');

$mediaTpl = TemplateRepository::get((int) $home['template_id']);
$mediaTpl['html'] = str_replace(
    '{{slot:content}}',
    '<img src="' . $media['path'] . '" alt="dot">{{slot:content}}',
    $mediaTpl['html']
);
$mediaPreview = (new Compiler())->compilePage((int) $home['id'], true, ['template' => $mediaTpl]);
expectContains('preview uses studio media', $mediaPreview, 'api/media/file/' . $media['id']);
$mediaPublish = (new Compiler())->compilePage((int) $home['id'], false, ['template' => $mediaTpl]);
expectContains('publish uses site assets', $mediaPublish, $media['path']);
expect('publish copied asset', is_file(MediaStore::publishedAbs($media['storage_key'])), true);
expect('publish has no studio url', str_contains($mediaPublish, 'api/media/file/'), false);

$errors = w3cErrors($written);
expect('w3c errors', $errors, []);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

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
