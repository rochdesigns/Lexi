<?php

use Symfony\Component\Finder\Finder;

/**
 * An icon class that is not defined anywhere fails silently and expensively: the
 * span renders its ligature name as plain text ("credit_card"), which no build,
 * type check or lint will complain about. These tests pin the small vocabulary
 * of icon classes the app actually styles.
 */
$iconClasses = ['ms', 'msf', 'material-symbols-rounded'];

function appStylesheet(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

/**
 * @return array<int, array{file: string, class: string}>
 */
function iconClassUsages(): array
{
    $finder = (new Finder)->files()->in(resource_path('js'))->name('*.vue');
    $usages = [];

    foreach ($finder as $file) {
        $contents = (string) $file->getContents();

        // Class tokens that read as an icon class: the two shorthands, or
        // anything in the material-symbols family.
        preg_match_all('/[\'"\s](ms|msf|material-symbols[\w-]*)[\'"\s]/', $contents, $matches);

        foreach (array_unique($matches[1]) as $class) {
            $usages[] = ['file' => $file->getRelativePathname(), 'class' => $class];
        }
    }

    return $usages;
}

test('every icon class the app styles is actually defined in the stylesheet', function () use ($iconClasses) {
    $stylesheet = appStylesheet();

    foreach ($iconClasses as $class) {
        expect($stylesheet)->toContain(".{$class} {");
    }
});

test('the icon shorthands declare their own font-family rather than relying on the remote stylesheet', function () {
    $stylesheet = appStylesheet();

    foreach (['ms', 'msf'] as $class) {
        $rule = str($stylesheet)->after(".{$class} {")->before('}')->toString();

        expect($rule)->toContain("font-family: 'Material Symbols Rounded'");
    }
});

test('no Vue file uses an icon class the stylesheet does not define', function () use ($iconClasses) {
    $undefined = collect(iconClassUsages())
        ->reject(fn (array $usage) => in_array($usage['class'], $iconClasses, true))
        ->map(fn (array $usage) => "{$usage['file']} uses .{$usage['class']}")
        ->values()
        ->all();

    expect($undefined)->toBe([]);
});

test('the Material Symbols font is requested by the app layout', function () {
    $layout = (string) file_get_contents(resource_path('views/app.blade.php'));

    expect($layout)->toContain('Material+Symbols+Rounded');
});
