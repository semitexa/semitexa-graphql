<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guard for the semitexa-graphql package.
 *
 * The package source must stay reusable framework code: no demo Article
 * domain, no application namespaces, no host-app references. Demo /
 * showcase code lives in the host application (under `src/modules/...`),
 * not inside this package.
 *
 * The guard is narrow on purpose — it catches the specific regression of
 * dropping demo files into `packages/semitexa-graphql/src/` without
 * pretending to be a full architecture suite.
 */
final class PackageStructureTest extends TestCase
{
    public function test_no_demo_namespace_or_directory_under_package_src(): void
    {
        $packageSrc = realpath(__DIR__ . '/../../src');
        self::assertNotFalse($packageSrc, 'package src directory not found');

        // Hard-fail on the specific anti-pattern of a Demo/ subdirectory.
        self::assertDirectoryDoesNotExist(
            $packageSrc . '/Demo',
            'packages/semitexa-graphql/src must not contain a Demo/ subdirectory; demo code belongs in the host application under src/modules/.'
        );

        // Soft scan: every PHP file under src/ must declare a Semitexa\Graphql\
        // namespace, never an App\ or Semitexa\Modules\ application namespace.
        $offenders = [];
        foreach ($this->iteratePhp($packageSrc) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^\s*namespace\s+(?P<ns>[^;]+);/m', $contents, $m) !== 1) {
                continue;
            }
            $namespace = trim($m['ns']);
            if (!str_starts_with($namespace, 'Semitexa\\Graphql')) {
                $offenders[] = "{$file} (namespace {$namespace})";
            }
        }

        self::assertSame([], $offenders, "Files outside the Semitexa\\Graphql namespace under src/:\n" . implode("\n", $offenders));
    }

    public function test_no_application_specific_classes_referenced_under_src(): void
    {
        $packageSrc = realpath(__DIR__ . '/../../src');
        self::assertNotFalse($packageSrc);

        $offenders = [];
        foreach ($this->iteratePhp($packageSrc) as $file) {
            $contents = (string) file_get_contents($file);
            // We're allowed to cite Core's Semitexa\Modules in docs strings,
            // but never as `use ...;` imports of application code.
            if (preg_match('/^\s*use\s+(Semitexa\\\\Modules\\\\|App\\\\)/m', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, "Package source must not import application namespaces:\n" . implode("\n", $offenders));
    }

    /** @return iterable<string> */
    private function iteratePhp(string $directory): iterable
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield (string) $file->getRealPath();
            }
        }
    }
}
