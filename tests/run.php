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

expect('version compare equal', Version::compare('2.0.0-alpha1', '2.0.0-alpha1'), 0);
expect('version compare alpha order', Version::compare('2.0.0-alpha1', '2.0.0-alpha2') < 0, true);
expect('version compare release after alpha', Version::compare('2.0.0', '2.0.0-alpha1') > 0, true);

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
expectContains('import header marker', $parsed['template_html'], '<!-- sneeze:header -->');
expectContains('import footer marker', $parsed['template_html'], '<!-- sneeze:footer -->');
expectContains('import header extracted', $parsed['header_html'], '<header>');
expectContains('import footer extracted', $parsed['footer_html'], '<footer>');
expectContains('import content', $parsed['content'], 'Hello imported');
expect('import content not in chrome', str_contains($parsed['template_html'], 'Hello imported'), false);
expectContains('import css extracted', $parsed['css'], 'color: red');
$frag = (new HtmlImporter())->parse('<section class="hero">Fragment</section>');
expect('fragment not document', $frag['is_document'], false);
expectContains('fragment body', $frag['content'], 'Fragment');

file_put_contents($installDir . '/VERSION', "2.0.0-alpha1\n");
file_put_contents($installDir . '/index.php', "<?php\n// sneeze stub\n");
Config::init($installDir);
expect('version current', Version::current(), '2.0.0-alpha1');
Database::connect(Config::dataDir());

$home = PageRepository::all()[0] ?? null;
expect('seeded home', $home['slug'] ?? null, 'index');

$chrome = DesignRepository::chrome();
expectContains('seeded header', $chrome['header_html'], 'site-header');
expectContains('seeded footer', $chrome['footer_html'], 'site-footer');

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
expect('markers replaced', str_contains($html, 'sneeze:header'), false);
if (str_contains($html, 'sneeze-block')) {
    $failed++;
    fwrite(STDERR, "FAIL publish html should not include preview chrome\n");
} else {
    $passed++;
    echo "ok  no preview chrome on publish\n";
}

$noHeader = (new Compiler())->compilePage((int) $home['id'], false, ['header_mode' => 'none']);
expect('header none', str_contains($noHeader, '<header class="site-header">'), false);
expectContains('footer still', $noHeader, '<footer class="site-footer">');
$customHeader = (new Compiler())->compilePage((int) $home['id'], false, [
    'header_mode' => 'custom',
    'header_html' => '<header class="custom-only">CustomNav</header>',
]);
expectContains('custom header', $customHeader, 'CustomNav');
expect('custom replaces site header', str_contains($customHeader, '<header class="site-header">'), false);

$script = DesignRepository::saveScript([
    'name' => 'Tracker',
    'js' => 'window.__sneezeTracker = 1;',
    'placement' => 'footer',
    'enabled' => true,
]);
$withScript = (new Compiler())->compilePage((int) $home['id'], false);
expectContains('global script included', $withScript, '__sneezeTracker');
$withoutScript = (new Compiler())->compilePage((int) $home['id'], false, [
    'disabled_script_ids' => [(int) $script['id']],
]);
expect('global script excluded', str_contains($withoutScript, '__sneezeTracker'), false);
DesignRepository::deleteScript((int) $script['id']);

$preview = (new Compiler())->compilePage((int) $home['id'], true);
expectContains('preview chrome', $preview, 'data-sneeze-block');
expectContains('preview draggable', $preview, 'draggable="true"');
expectContains('preview dropzone', $preview, 'sneeze-dropzone');
expectContains('preview drop message', $preview, 'sneeze:drop');
expectContains('preview edit message', $preview, 'sneeze:edit');
expectContains('preview editable field', $preview, 'sneeze-editable');
expectContains('preview posts nav', $preview, 'posts/index.html');

$mdHtml = Markdown::toHtml("# Hello\n\nA **bold** [link](https://example.com).\n\n- one\n- two");
expectContains('md heading', $mdHtml, '<h1>Hello</h1>');
expectContains('md strong', $mdHtml, '<strong>bold</strong>');
expectContains('md link', $mdHtml, 'href="https://example.com"');
expectContains('md list', $mdHtml, '<ul>');

$builtinId = CmsRepository::ensureBuiltinSource();
expect('builtin source id', $builtinId > 0, true);
$slugs = array_column(BlockTypeRepository::all(), 'slug');
expect('cms_entry block', in_array('cms_entry', $slugs, true), true);
expect('cms_list block', in_array('cms_list', $slugs, true), true);
$tplSlugs = array_column(TemplateRepository::all(), 'slug');
expect('post template', in_array('post', $tplSlugs, true), true);

$post = PostRepository::save([
    'title' => 'Launch notes',
    'slug' => 'launch-notes',
    'status' => 'published',
    'body_markdown' => "## Why it matters\n\nShip **static** HTML with SEO.\n\n![Hero](uploads/hero.jpg)",
    'excerpt' => 'How Sneeze publishes posts.',
    'author_name' => 'Ada',
    'categories' => ['news'],
    'tags' => ['seo', 'ai'],
    'seo_title' => 'Launch notes | Sneeze',
    'seo_description' => 'SEO friendly launch notes for AI and search.',
    'featured_image' => 'uploads/hero.jpg',
]);
expect('post saved', $post['slug'], 'launch-notes');
expectContains('post html from md', $post['body_html'], '<h2>Why it matters</h2>');
$entries = CmsRepository::entries($builtinId);
expect('builtin cache entry', count($entries) >= 1, true);
$synced = (new CmsAdapter())->sync(CmsRepository::get($builtinId));
expect('builtin sync fetched', $synced['fetched'] >= 1, true);

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
expect('sitemap written', is_file($siteRoot . '/sitemap.xml'), true);
expect('feed written', is_file($siteRoot . '/feed.xml'), true);

$postsPublish = (new Publisher())->publishPosts(0);
expect('post file written', is_file($siteRoot . '/posts/launch-notes.html'), true);
expect('post index written', is_file($siteRoot . '/posts/index.html'), true);
$postHtml = file_get_contents($siteRoot . '/posts/launch-notes.html') ?: '';
expectContains('post json-ld', $postHtml, 'BlogPosting');
expectContains('post og title', $postHtml, 'property="og:title"');
expectContains('post twitter card', $postHtml, 'twitter:card');
expectContains('post canonical', $postHtml, 'rel="canonical"');
expectContains('post article body', $postHtml, 'Why it matters');
$sitemap = file_get_contents($siteRoot . '/sitemap.xml') ?: '';
expectContains('sitemap has post', $sitemap, 'posts/launch-notes.html');
$feed = file_get_contents($siteRoot . '/feed.xml') ?: '';
expectContains('rss item', $feed, 'Launch notes');

$listType = null;
foreach (BlockTypeRepository::all() as $type) {
    if ($type['slug'] === 'cms_list') {
        $listType = $type;
        break;
    }
}
$listPreview = (new Compiler())->compileDraft(
    [
        'id' => $home['id'],
        'title' => 'Home',
        'slug' => 'index',
        'seo_title' => 'Home',
        'seo_description' => '',
        'header_mode' => 'none',
        'footer_mode' => 'none',
        'custom_css' => '',
        'custom_js' => '',
    ],
    TemplateRepository::get((int) $home['template_id']),
    [[
        'id' => -10,
        'block_type_id' => $listType['id'],
        'block_type' => $listType,
        'slot' => 'content',
        'cms_source_id' => $builtinId,
        'cms_entry_key' => null,
        'data' => ['heading' => 'From CMS', 'limit' => 5],
        'children' => [],
    ]],
    false
);
expectContains('cms list renders entry', $listPreview, 'Launch notes');

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

if (class_exists('ZipArchive')) {
    $pkgRoot = $installDir . '/pkg-src';
    mkdir($pkgRoot . '/lib', 0755, true);
    file_put_contents($pkgRoot . '/index.php', "<?php\necho 'updated';\n");
    file_put_contents($pkgRoot . '/VERSION', "2.0.0-alpha2\n");
    file_put_contents($pkgRoot . '/lib/Hello.php', "<?php\nclass Hello {}\n");
    file_put_contents($installDir . '/data/keep-me.txt', 'preserve');
    $nested = $installDir . '/zip-nested/andreherberth-sneeze-abc123';
    mkdir($nested . '/lib', 0755, true);
    foreach (['index.php', 'VERSION'] as $f) {
        copy($pkgRoot . '/' . $f, $nested . '/' . $f);
    }
    copy($pkgRoot . '/lib/Hello.php', $nested . '/lib/Hello.php');
    $zipPath = $installDir . '/update.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zipBase = 'andreherberth-sneeze-abc123';
    $zip->addFile($nested . '/index.php', $zipBase . '/index.php');
    $zip->addFile($nested . '/VERSION', $zipBase . '/VERSION');
    $zip->addFile($nested . '/lib/Hello.php', $zipBase . '/lib/Hello.php');
    $zip->close();
    $result = Updater::applyUpload([
        'name' => 'update.zip',
        'tmp_name' => $zipPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($zipPath),
        'type' => 'application/zip',
    ]);
    expect('update version', $result['version'], '2.0.0-alpha2');
    expect('update files', $result['files_updated'] > 0, true);
    expectContains('update wrote index', (string) file_get_contents($installDir . '/index.php'), 'updated');
    expect('update preserved data', is_file($installDir . '/data/keep-me.txt'), true);
    expect('update preserved db', is_file($installDir . '/data/sneeze.db'), true);
} else {
    echo "skip updater (ZipArchive missing)\n";
}

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
