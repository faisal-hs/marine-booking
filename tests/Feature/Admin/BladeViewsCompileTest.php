<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Blade;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Blade compiles a view to PHP, but compiling it does not PARSE it. A template can compile
 * "successfully" into code that is not valid PHP, and the error only appears when that
 * page is first opened, as a server error that hides its real cause.
 *
 * The usual culprit: an inline `@php($x = ...)` followed anywhere later in the same file
 * by a block `@php ... @endphp`. Blade pairs the inline one with the LATER @endphp and
 * swallows the HTML between them into a PHP block. This test compiles every view and parses
 * the generated PHP, so such a mistake fails here instead of on a live page.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_every_view_compiles_to_valid_php(): void
    {
        $views = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $views[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(50, count($views), 'Expected to find the whole set of views.');

        $broken = [];

        foreach ($views as $path) {
            try {
                token_get_all(Blade::compileString(file_get_contents($path)), TOKEN_PARSE);
            } catch (ParseError $e) {
                $broken[] = str_replace(resource_path('views').'/', '', $path).': '.$e->getMessage().' (line '.$e->getLine().' of the compiled view)';
            }
        }

        $this->assertSame([], $broken, "These views compile to invalid PHP:\n - ".implode("\n - ", $broken));
    }

    public function test_the_check_really_catches_the_inline_php_trap(): void
    {
        // Proves the test above is not vacuous: the exact mistake that broke two pages.
        $trap = "@php(\$deleted = true)\n<div>hello</div>\n@php\n    \$other = 1;\n@endphp\n";

        $this->expectException(ParseError::class);

        token_get_all(Blade::compileString($trap), TOKEN_PARSE);
    }
}
