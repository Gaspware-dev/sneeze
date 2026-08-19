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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
