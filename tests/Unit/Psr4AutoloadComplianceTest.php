<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every class, trait, interface and enum must live at the path its namespace says (PSR-4), or Composer cannot find it.
 *
 * This is the failure a file in the wrong folder causes, and it is silent until the moment something uses the class: the file
 * exists, the editor shows it, and the app dies with "Class ... not found" (or "Trait ... not found") only when that code runs.
 * It happened when files were copied one by one and lost a folder. This test finds it at once, naming the file and where it belongs.
 *
 * The folders and namespaces are read from composer.json, so a new autoload mapping is covered without changing this test.
 */
class Psr4AutoloadComplianceTest extends TestCase
{
    /** @return array<string, string> namespace prefix => absolute directory */
    private function mappings(): array
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        $map = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($composer[$section]['psr-4'] ?? [] as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    $map[$prefix] = dirname(__DIR__, 2).'/'.trim($dir, '/');
                }
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_every_class_is_in_the_folder_its_namespace_says(): void
    {
        $misplaced = [];
        $checked = 0;

        foreach ($this->mappings() as $prefix => $dir) {
            foreach ($this->phpFiles($dir) as $path) {
                $source = file_get_contents($path);

                // Files that declare no named class (migrations return an anonymous one; routes and config return arrays) have nothing to find.
                if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                    || ! preg_match_all('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $source, $declared)) {
                    continue;
                }

                // A file may also declare small helper classes (tests do); PSR-4 is about the one named like the file, or, if none is, the first.
                $own = pathinfo($path, PATHINFO_FILENAME);
                $name = in_array($own, $declared[1], true) ? $own : $declared[1][0];

                $checked++;
                $fullName = $namespace[1].'\\'.$name;

                if (! str_starts_with($fullName, $prefix)) {
                    $misplaced[] = "{$this->relative($path)} declares {$fullName}, which is outside the {$prefix} namespace of its folder";

                    continue;
                }

                $expected = $dir.'/'.str_replace('\\', '/', substr($fullName, strlen($prefix))).'.php';

                if ($path !== $expected) {
                    $misplaced[] = "{$this->relative($path)} declares {$fullName}: move it to {$this->relative($expected)}";
                }
            }
        }

        $this->assertGreaterThan(300, $checked, 'Expected to check the whole codebase, not a few files');
        $this->assertSame([], $misplaced, "These files are in the wrong folder, so the application cannot load them:\n  ".implode("\n  ", $misplaced));
    }

    public function test_the_test_notices_a_misplaced_file(): void
    {
        // Proof that the rule above has teeth: the same check, applied to a file that is deliberately in the wrong place.
        $source = "<?php\n\nnamespace Database\\Seeders\\Concerns;\n\ntrait PricesReservations\n{\n}\n";
        $wrong = dirname(__DIR__, 2).'/database/seeders/Bulk/Concerns/PricesReservations.php';

        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
        preg_match('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $source, $name);
        $expected = dirname(__DIR__, 2).'/database/seeders/'.str_replace('\\', '/', substr($namespace[1].'\\'.$name[1], strlen('Database\\Seeders\\'))).'.php';

        $this->assertNotSame($wrong, $expected, 'A trait in Bulk/Concerns declaring Database\\Seeders\\Concerns must be reported as misplaced');
        $this->assertSame(dirname(__DIR__, 2).'/database/seeders/Concerns/PricesReservations.php', $expected);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(dirname(__DIR__, 2), '', $path), '/');
    }
}
