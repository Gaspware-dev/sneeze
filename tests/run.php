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

$repoPlugins = dirname(__DIR__) . '/plugins/example';
$parsedExample = PluginManifest::parse($repoPlugins);
expect('plugin asset uri kept', Plugins::normalizeUri('/plugin-assets/example/panel.js', '/plugin-assets/example/panel.js'), '/plugin-assets/example/panel.js');
expect('subdir api uri stripped', Plugins::normalizeUri('/sneeze/api/plugins', '/sneeze/index.php'), '/api/plugins');
expect('example manifest id', $parsedExample['id'], 'example');
expect('example manifest api', $parsedExample['api'], PluginApi::VERSION);
$exampleTone = null;
foreach ($parsedExample['settings'] as $setting) {
    if (($setting['key'] ?? '') === 'tone') {
        $exampleTone = $setting;
    }
}
expect('example tone type', $exampleTone['type'] ?? '', 'select');
expect('example tone option', $exampleTone['options'][0]['value'] ?? '', 'plain');
expect('example tone label', $exampleTone['options'][0]['label'] ?? '', 'plain');

$publicMethods = [];
foreach ((new ReflectionClass(PluginApi::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getDeclaringClass()->getName() !== 'PluginApi') {
        continue;
    }
    $publicMethods[] = $method->getName();
}
$catalogued = array_column(PluginApi::reference(), 'method');
sort($publicMethods);
$sortedCatalog = $catalogued;
sort($sortedCatalog);
expect('api catalog matches methods', $sortedCatalog, $publicMethods);
expect('api catalog unique', count($catalogued) === count(array_unique($catalogued)), true);

$hookNames = array_column(PluginHooks::catalog(), 'name');
$hookConstants = [];
foreach ((new ReflectionClass(PluginHooks::class))->getReflectionConstants() as $constant) {
    if ($constant->getDeclaringClass()->getName() !== 'PluginHooks') {
        continue;
    }
    if (is_string($constant->getValue())) {
        $hookConstants[] = $constant->getValue();
    }
}
sort($hookNames);
sort($hookConstants);
expect('hook catalog matches constants', $hookNames, $hookConstants);
foreach (PluginHooks::catalog() as $hook) {
    expect('hook kind ' . $hook['name'], in_array($hook['kind'], ['filter', 'action'], true), true);
}

$described = array_column(PluginManifest::describe(), 'name');
expect('manifest describe covers fields', $described, array_keys(PluginManifest::FIELDS));
$exampleJson = json_decode((string) file_get_contents($repoPlugins . '/plugin.json'), true);
foreach (array_keys($exampleJson) as $field) {
    expect('example field documented ' . $field, in_array($field, $described, true), true);
}
$referenceDoc = Plugins::referencePayload();
expect('reference api version', $referenceDoc['api_version'], 1);
expect('reference has client', count($referenceDoc['client']) >= 8, true);
expect('reference has notes', count($referenceDoc['notes']) >= 1, true);
$clientNames = array_column($referenceDoc['client'], 'name');
foreach (['sneeze.stream', 'sneeze.studio.patchBlockData', 'sneeze.studio.undoCheckpoint', 'sneeze.studio.openWorkspace', 'sneeze.studio.openSideTab'] as $clientName) {
    expect('client documents ' . $clientName, in_array($clientName, $clientNames, true), true);
}
$noteText = implode("\n", $referenceDoc['notes']);
expectContains('reference mentions execution time', $noteText, 'max_execution_time');
expectContains('reference mentions stream', $noteText, 'sneeze.stream');

$fixture = $installDir . '/manifest-fixtures';
mkdir($fixture . '/escape', 0755, true);
file_put_contents($fixture . '/escape/plugin.json', json_encode([
    'id' => 'escape',
    'name' => 'Escape',
    'version' => '1',
    'description' => '',
    'api' => 1,
    'entry' => '../index.php',
    'class' => 'EscapePlugin',
    'capabilities' => [],
]));
try {
    PluginManifest::parse($fixture . '/escape');
    expect('manifest rejects traversal', false, true);
} catch (PluginException $e) {
    expectContains('manifest rejects traversal', $e->getMessage(), 'escapes');
}

mkdir($fixture . '/bad-api', 0755, true);
file_put_contents($fixture . '/bad-api/Plugin.php', "<?php\nclass BadApiPlugin implements SneezePlugin { public function boot(PluginApi \$api): void {} }\n");
file_put_contents($fixture . '/bad-api/plugin.json', json_encode([
    'id' => 'bad-api',
    'name' => 'Bad',
    'version' => '1',
    'description' => '',
    'api' => '1',
    'entry' => 'Plugin.php',
    'class' => 'BadApiPlugin',
    'capabilities' => [],
]));
try {
    PluginManifest::parse($fixture . '/bad-api');
    expect('manifest rejects string api', false, true);
} catch (PluginException $e) {
    expectContains('manifest rejects string api', $e->getMessage(), 'integer');
}

$reader = PluginApi::forPlugin([
    'id' => 'reader',
    'name' => 'Reader',
    'version' => '1',
    'capabilities' => ['site.read'],
], $installDir);
$pageCount = count(PageRepository::all());
try {
    $reader->createPage(['title' => 'Nope']);
    expect('capability denied', false, true);
} catch (PluginException $e) {
    expectContains('capability denied', $e->getMessage(), 'site.write');
}
expect('capability wrote nothing', count(PageRepository::all()), $pageCount);
$reader->setOption('label', 'kept');
expect('option roundtrip', $reader->option('label'), 'kept');
expect('options map', $reader->options()['label'] ?? null, 'kept');
$reader->setOption('label', null);
expect('option deleted', $reader->option('label', 'gone'), 'gone');
try {
    $reader->path('../index.php');
    expect('path rejects traversal', false, true);
} catch (PluginException $e) {
    expectContains('path rejects traversal', $e->getMessage(), 'escapes');
}
expect('path stays inside', str_starts_with($reader->path(''), realpath($installDir)), true);

$oldDir = $installDir . '/plugins/oldapi';
mkdir($oldDir, 0755, true);
file_put_contents($oldDir . '/Plugin.php', "<?php\nclass OldApiPlugin implements SneezePlugin { public function boot(PluginApi \$api): void { \$api->setOption('booted', true); } }\n");
file_put_contents($oldDir . '/plugin.json', json_encode([
    'id' => 'oldapi',
    'name' => 'Old',
    'version' => '0',
    'description' => 'Wrong API',
    'api' => 99,
    'entry' => 'Plugin.php',
    'class' => 'OldApiPlugin',
    'capabilities' => ['compile'],
]));
copyPluginDir($repoPlugins, $installDir . '/plugins/example');
copyPluginDir($repoPlugins, $installDir . '/data/plugins/example');
$overrideManifest = json_decode((string) file_get_contents($installDir . '/data/plugins/example/plugin.json'), true);
$overrideManifest['version'] = '9.9.9';
file_put_contents($installDir . '/data/plugins/example/plugin.json', json_encode($overrideManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

Plugins::boot();
$listed = Plugins::listPayload(true)['plugins'];
$old = pluginById($listed, 'oldapi');
expect('old api not enabled', $old['enabled'] ?? true, false);
expectContains('old api error', (string) ($old['error'] ?? ''), 'not supported');
expect('old api class not loaded', class_exists('OldApiPlugin', false), false);
$override = pluginById($listed, 'example');
expect('user plugin overrides bundled', $override['origin'] ?? '', 'user');
expect('user plugin version', $override['version'] ?? '', '9.9.9');
expect('override stays disabled', $override['enabled'] ?? true, false);
expect('disabled plugin has no route', Plugins::accessFor('GET', '/api/plugin/example/ping'), null);

Plugins::delete('example');
$afterDelete = pluginById(Plugins::listPayload(false)['plugins'], 'example');
expect('bundled remains after user delete', $afterDelete['origin'] ?? '', 'bundled');
expect('bundled version restored', $afterDelete['version'] ?? '', '1.0.0');
expect('user copy removed', is_dir($installDir . '/data/plugins/example'), false);

$enabled = Plugins::enable('example');
expect('example enabled', $enabled['enabled'], true);
expect('example booted tab', count($enabled['contributions']['side_tabs'] ?? []) === 1, true);
expect('example class loaded', class_exists('ExamplePlugin', false), true);
$getAccess = Plugins::accessFor('GET', '/api/plugin/example/ping');
expect('route requires auth', $getAccess['auth'] ?? false, true);
expect('get skips csrf', $getAccess['csrf'] ?? true, false);
expect('get is not admin', $getAccess['admin'] ?? true, false);
$postAccess = Plugins::accessFor('POST', '/api/plugin/example/echo');
expect('post requires csrf', $postAccess['csrf'] ?? false, true);
expect('post requires auth', $postAccess['auth'] ?? false, true);
$adminAccess = Plugins::accessFor('GET', '/api/plugin/example/admin-ping');
expect('admin route flagged', $adminAccess['admin'] ?? false, true);
$pong = Plugins::invoke('GET', '/api/plugin/example/ping');
expect('ping ok', $pong['ok'] ?? false, true);
expect('ping id', $pong['plugin'] ?? '', 'example');
expect('asset traversal blocked', Plugins::resolveAsset('example', '../Plugin.php'), null);
expect('asset served from assets dir', is_file((string) Plugins::resolveAsset('example', 'panel.js')), true);
expect('disabled old api asset hidden', Plugins::resolveAsset('oldapi', 'Plugin.php'), null);

$exampleApi = PluginApi::forPlugin(
    PluginManifest::parse($installDir . '/plugins/example'),
    $installDir . '/plugins/example'
);
$exampleApi->setOption('note', 'hello');
$noted = (new Compiler())->compilePage((int) $home['id'], false);
expectContains('compile hook note', $noted, '<!-- example:hello -->');
$previewNoted = (new Compiler())->compilePage((int) $home['id'], true);
expect('compile hook skips preview', str_contains($previewNoted, 'example:hello'), false);

PluginHooks::listen(PluginHooks::COMPILE_HTML, static function (): void {
    throw new RuntimeException('boom');
}, 5, 'bad', 'filter');
$isolated = (new Compiler())->compilePage((int) $home['id'], false);
expectContains('throwing listener isolated', $isolated, '<!-- example:hello -->');
expectContains('listener error recorded', PluginHooks::errors()['bad'] ?? '', 'boom');
PluginHooks::forget('bad');

PluginHooks::listen(PluginHooks::PUBLISH_BEFORE, static function (): void {
    throw new RuntimeException('publish-boom');
}, 10, 'pub', 'action');
$republish = (new Publisher())->publishPage((int) $home['id']);
expect('publish survived listener', $republish['ok'] ?? false, true);
expectContains('publish listener error', PluginHooks::errors()['pub'] ?? '', 'publish-boom');
PluginHooks::forget('pub');
$exampleApi->setOption('note', null);

if (class_exists('ZipArchive')) {
    $slip = $installDir . '/slip.zip';
    $slipZip = new ZipArchive();
    $slipZip->open($slip, ZipArchive::CREATE);
    $slipZip->addFromString('../evil.php', '<?php echo 1;');
    $slipZip->addFromString('plugin.json', '{}');
    $slipZip->close();
    try {
        Plugins::installZip([
            'name' => 'slip.zip',
            'tmp_name' => $slip,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($slip),
        ]);
        expect('zip slip rejected', false, true);
    } catch (PluginException $e) {
        expectContains('zip slip rejected', $e->getMessage(), 'unsafe');
    }
    expect('zip slip did not escape', is_file($installDir . '/evil.php') || is_file($installDir . '/data/evil.php'), false);

    $noteSrc = $installDir . '/note-src';
    mkdir($noteSrc, 0755, true);
    file_put_contents($noteSrc . '/Plugin.php', "<?php\nclass NotePlugin implements SneezePlugin { public function boot(PluginApi \$api): void {} }\n");
    file_put_contents($noteSrc . '/plugin.json', json_encode([
        'id' => 'note',
        'name' => 'Note',
        'version' => '0.1.0',
        'description' => 'Installed from zip',
        'api' => 1,
        'entry' => 'Plugin.php',
        'class' => 'NotePlugin',
        'capabilities' => [],
    ]));
    $noteZipPath = $installDir . '/note.zip';
    $noteZip = new ZipArchive();
    $noteZip->open($noteZipPath, ZipArchive::CREATE);
    $noteZip->addFile($noteSrc . '/plugin.json', 'wrapped/plugin.json');
    $noteZip->addFile($noteSrc . '/Plugin.php', 'wrapped/Plugin.php');
    $noteZip->close();
    $installed = Plugins::installZip([
        'name' => 'note.zip',
        'tmp_name' => $noteZipPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($noteZipPath),
    ]);
    expect('zip install id', $installed['id'], 'note');
    expect('zip install disabled', $installed['enabled'], false);
    expect('zip install origin', $installed['origin'], 'user');
    expect('zip class not loaded yet', class_exists('NotePlugin', false), false);
    expect('example still enabled after install', pluginById(Plugins::listPayload(false)['plugins'], 'example')['enabled'] ?? false, true);
    $noteEnabled = Plugins::enable('note');
    expect('zip plugin enabled', $noteEnabled['enabled'], true);
    expect('zip class loaded', class_exists('NotePlugin', false), true);
    Plugins::delete('note');
    expect('zip plugin removed', pluginById(Plugins::listPayload(false)['plugins'], 'note'), null);
    expect('zip files removed', is_dir($installDir . '/data/plugins/note'), false);
} else {
    echo "skip plugin zip (ZipArchive missing)\n";
}

$headingType = null;
foreach (BlockTypeRepository::all() as $type) {
    if (($type['slug'] ?? '') === 'heading') {
        $headingType = $type;
    }
}
expect('heading type seeded', $headingType !== null, true);
$draftTree = [
    [
        'id' => 1,
        'block_type_id' => (int) $headingType['id'],
        'slot' => 'content',
        'data' => ['text' => 'A', 'level' => 'h2'],
        'children' => [],
    ],
    [
        'id' => 2,
        'block_type_id' => (int) $headingType['id'],
        'slot' => 'content',
        'data' => ['text' => 'B', 'level' => 'h2'],
        'children' => [
            [
                'id' => 3,
                'slot' => 'content',
                'data' => ['text' => 'C', 'level' => 'h3'],
                'children' => [],
            ],
        ],
    ],
];
$patchedTree = PluginDraft::patchBlockData($draftTree, 1, ['text' => 'A2']);
expect('patch changes target', $patchedTree[0]['data']['text'], 'A2');
expect('patch keeps other keys', $patchedTree[0]['data']['level'], 'h2');
expect('patch leaves sibling', $patchedTree[1]['data']['text'], 'B');
expect('patch leaves child', $patchedTree[1]['children'][0]['data']['text'], 'C');
expect('patch leaves source', $draftTree[0]['data']['text'], 'A');
$replacedTree = PluginDraft::updateBlockData($draftTree, 2, ['text' => 'only']);
expect('update replaces data', $replacedTree[1]['data'], ['text' => 'only']);
expect('update leaves sibling', $replacedTree[0]['data']['text'], 'A');
$removedTree = PluginDraft::removeBlock($draftTree, 2);
expect('remove drops block', count($removedTree), 1);
expect('remove keeps sibling', $removedTree[0]['id'], 1);
$insertedTree = PluginDraft::insertBlock($draftTree, [
    'slug' => 'heading',
    'index' => 0,
    'data' => ['text' => 'New'],
]);
expect('insert at index', $insertedTree[0]['data']['text'], 'New');
expect('insert uses negative id', $insertedTree[0]['id'] < 0, true);
expect('insert shifts sibling', $insertedTree[1]['id'], 1);
expect('insert keeps following', $insertedTree[2]['id'], 2);
expect('insert leaves source count', count($draftTree), 2);
$childTree = PluginDraft::insertBlock($draftTree, [
    'type_id' => (int) $headingType['id'],
    'parent_id' => 2,
    'index' => 0,
    'data' => ['text' => 'Child'],
]);
expect('insert under parent', $childTree[1]['children'][0]['data']['text'], 'Child');
expect('insert keeps previous child', $childTree[1]['children'][1]['id'], 3);
$writer = PluginApi::forPlugin([
    'id' => 'writer',
    'name' => 'Writer',
    'version' => '1',
    'capabilities' => ['site.write'],
], $installDir);
expect('api patch matches helper', $writer->patchBlockData($draftTree, 1, ['text' => 'A2'])[0]['data']['text'], 'A2');
try {
    PluginDraft::removeBlock($draftTree, 99);
    expect('missing block rejected', false, true);
} catch (PluginException $e) {
    expectContains('missing block rejected', $e->getMessage(), 'not found');
}
try {
    PluginDraft::insertBlock($draftTree, ['slug' => 'heading', 'parent_id' => 99]);
    expect('missing parent rejected', false, true);
} catch (PluginException $e) {
    expectContains('missing parent rejected', $e->getMessage(), 'Parent');
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$imported = $writer->importMediaBytes('pixel.png', $png);
expect('media bytes mime', $imported['mime'] ?? '', 'image/png');
expect('media bytes stored', is_file(Config::dataDir() . '/uploads/' . ($imported['storage_key'] ?? '')), true);
try {
    $writer->importMediaFromUrl('file:///etc/passwd');
    expect('media url rejects file scheme', false, true);
} catch (PluginException $e) {
    expectContains('media url rejects file scheme', $e->getMessage(), 'http');
}
try {
    $writer->importMediaFromUrl('ftp://example.com/a.png');
    expect('media url rejects ftp', false, true);
} catch (PluginException $e) {
    expectContains('media url rejects ftp', $e->getMessage(), 'http');
}
$toolchainOff = $writer->saveToolchain(['typescript' => false]);
expect('toolchain saved off', $toolchainOff['typescript'] ?? true, false);
$toolchainOn = $writer->saveToolchain(['typescript' => true]);
expect('toolchain saved on', $toolchainOn['typescript'] ?? false, true);

$selectDir = $installDir . '/plugins/selectdemo';
mkdir($selectDir, 0755, true);
file_put_contents($selectDir . '/Plugin.php', "<?php\nclass SelectDemoPlugin implements SneezePlugin { public function boot(PluginApi \$api): void {} }\n");
file_put_contents($selectDir . '/plugin.json', json_encode([
    'id' => 'selectdemo',
    'name' => 'Select demo',
    'version' => '1',
    'description' => '',
    'api' => 1,
    'entry' => 'Plugin.php',
    'class' => 'SelectDemoPlugin',
    'capabilities' => [],
    'settings' => [[
        'key' => 'provider',
        'label' => 'Provider',
        'type' => 'select',
        'options' => ['openai', ['value' => 'mistral', 'label' => 'Mistral']],
    ]],
]));
$selectManifest = PluginManifest::parse($selectDir);
expect('select string option', $selectManifest['settings'][0]['options'][0], ['value' => 'openai', 'label' => 'openai']);
expect('select pair option', $selectManifest['settings'][0]['options'][1], ['value' => 'mistral', 'label' => 'Mistral']);
$badSelect = $selectDir . '-bad';
mkdir($badSelect, 0755, true);
copy($selectDir . '/Plugin.php', $badSelect . '/Plugin.php');
$badJson = json_decode((string) file_get_contents($selectDir . '/plugin.json'), true);
$badJson['id'] = 'selectdemo-bad';
$badJson['class'] = 'SelectDemoPlugin';
$badJson['settings'][0]['options'] = [];
file_put_contents($badSelect . '/plugin.json', json_encode($badJson));
try {
    PluginManifest::parse($badSelect);
    expect('select requires options', false, true);
} catch (PluginException $e) {
    expectContains('select requires options', $e->getMessage(), 'options');
}
Plugins::reboot();
Plugins::enable('selectdemo');
$selectSaved = Plugins::saveSettings('selectdemo', ['provider' => 'mistral']);
$providerRow = null;
foreach ($selectSaved as $row) {
    if (($row['key'] ?? '') === 'provider') {
        $providerRow = $row;
    }
}
expect('select setting saved', $providerRow['value'] ?? '', 'mistral');
try {
    Plugins::saveSettings('selectdemo', ['provider' => 'nope']);
    expect('select rejects unknown', false, true);
} catch (PluginException $e) {
    expectContains('select rejects unknown', $e->getMessage(), 'listed');
}
expect('select keeps previous', PluginApi::forPlugin($selectManifest, $selectDir)->option('provider'), 'mistral');

expect('slow route streams', Plugins::routeStreams('GET', '/api/plugin/example/slow'), true);
expect('ping route is json', Plugins::routeStreams('GET', '/api/plugin/example/ping'), false);
$slow = Plugins::invoke('GET', '/api/plugin/example/slow');
expect('slow returns generator', $slow instanceof Generator, true);
$slowEvents = [];
foreach ($slow as $event) {
    $slowEvents[] = $event;
}
expect('slow event count', count($slowEvents), 2);
expect('slow event done', $slowEvents[1]['done'] ?? false, true);
$encoded = Plugins::encodeEvent($slowEvents[0]) . Plugins::encodeEvent($slowEvents[1]);
expectContains('sse data line', $encoded, 'data: {"step":1}');
expect('sse frames', substr_count($encoded, "\n\n"), 2);

$errors = w3cErrors($written);
expect('w3c errors', $errors, []);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

function copyPluginDir(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException('Could not copy plugin directory');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $dest = $to . '/' . $iterator->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            continue;
        }
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($item->getPathname(), $dest);
    }
}

function pluginById(array $plugins, string $id): ?array
{
    foreach ($plugins as $plugin) {
        if (($plugin['id'] ?? '') === $id) {
            return $plugin;
        }
    }
    return null;
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
