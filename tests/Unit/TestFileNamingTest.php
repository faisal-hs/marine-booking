<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * PHPUnit only runs files whose name ends in "Test.php". A test file saved
 * under any other name is skipped silently: the suite stays green while the
 * tests in it never run. That already happened once in this project (a
 * file containing 11 tests was saved as ExplodingTestNotification.php, after
 * the first helper class in it, and went unnoticed across several runs).
 *
 * This guard makes that mistake fail loudly instead:
 *
 *  - every class that extends TestCase must live in a file named after it,
 *    and its name must end in "Test";
 *  - no file whose name does not end in "Test.php" may contain test methods.
 *
 * Helper classes are fine: a test file may declare as many non-test helper
 * classes as it likes (the notification stubs in NotificationFailureIsolationTest
 * do), because only classes extending TestCase are checked.
 */
class TestFileNamingTest extends TestCase
{
    /** Files that legitimately hold a TestCase but are not tests themselves. */
    private const EXEMPT = ['TestCase.php', 'Pest.php'];

    /** @return array<string, string> relative path => file contents, for every PHP file under tests/ */
    private function testFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), self::EXEMPT, true)) {
                continue;
            }

            $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    public function test_the_scan_actually_sees_the_test_suite(): void
    {
        // Guards the guard: if the scan matched nothing, the tests below would pass vacuously.
        $this->assertGreaterThan(50, count($this->testFiles()), 'Expected to find the whole test suite under tests/.');
    }

    public function test_every_test_class_lives_in_a_file_named_after_it_and_ending_in_test(): void
    {
        $problems = [];

        foreach ($this->testFiles() as $path => $source) {
            preg_match_all('/^(?:final\s+)?class\s+(\w+)\s+extends\s+\\\\?(?:[\w\\\\]*\\\\)?TestCase\b/m', $source, $matches);

            foreach ($matches[1] as $class) {
                if (basename($path) !== $class.'.php') {
                    $problems[] = "{$path} declares test class {$class}: the file must be named {$class}.php";
                } elseif (! str_ends_with($class, 'Test')) {
                    $problems[] = "{$path}: test class {$class} must end in \"Test\" or PHPUnit will not run it";
                }
            }
        }

        $this->assertSame([], $problems, "Test files PHPUnit would skip or mis-load:\n - ".implode("\n - ", $problems));
    }

    public function test_no_file_that_phpunit_would_skip_contains_test_methods(): void
    {
        $problems = [];

        foreach ($this->testFiles() as $path => $source) {
            if (str_ends_with($path, 'Test.php')) {
                continue;
            }

            if (preg_match('/function\s+test_\w+\s*\(|#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\s*\]/', $source)) {
                $problems[] = "{$path} contains test methods but its name does not end in Test.php";
            }
        }

        $this->assertSame([], $problems, "These files hold tests that PHPUnit will never run:\n - ".implode("\n - ", $problems));
    }
}
