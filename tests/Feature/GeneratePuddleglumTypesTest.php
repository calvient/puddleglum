<?php

namespace Calvient\Puddleglum\Tests\Feature;

use Calvient\Puddleglum\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class GeneratePuddleglumTypesTest extends TestCase
{
    private string $fixtureRoot;

    private string $previousWorkingDirectory;

    /** @var callable(string): void */
    private $appAutoloader;

    public function setUp(): void
    {
        parent::setUp();

        $this->previousWorkingDirectory = getcwd();
        $this->fixtureRoot = sys_get_temp_dir() . '/puddleglum-fixture-' . bin2hex(random_bytes(8));
        $this->createFixtureApp();
        $this->registerFixtureAutoloader();
        $this->resetFixtureCounters();
        $this->registerFixtureRoutes();
    }

    public function tearDown(): void
    {
        if (isset($this->appAutoloader)) {
            spl_autoload_unregister($this->appAutoloader);
        }

        chdir($this->previousWorkingDirectory);
        $this->deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_generate_command_emits_types_for_a_laravel_backend(): void
    {
        $outputDirectory = $this->generateTypes();

        $this->assertGeneratedTreeMatchesFixture($outputDirectory);
    }

    public function test_generate_command_does_not_rewrite_unchanged_files(): void
    {
        $outputDirectory = $this->generateTypes();
        $utilsFile = $outputDirectory . '/utils.ts';

        touch($utilsFile, 1234567890);

        $this->generateTypes();

        clearstatcache(true, $utilsFile);
        $this->assertSame(1234567890, filemtime($utilsFile));
    }

    public function test_generate_command_deletes_stale_generated_files(): void
    {
        $outputDirectory = $this->generateTypes();
        $staleFile = $outputDirectory . '/api/puddleglum/StaleController.ts';

        file_put_contents($staleFile, 'export default class StaleController {}');

        $this->generateTypes();

        $this->assertFileDoesNotExist($staleFile);
    }

    public function test_generate_command_invokes_each_model_relation_once(): void
    {
        $this->generateTypes();

        $this->assertSame(1, \App\Models\Category::$productsRelationCalls);
        $this->assertSame(1, \App\Models\Feature::$productRelationCalls);
        $this->assertSame(1, \App\Models\Product::$categoryRelationCalls);
        $this->assertSame(1, \App\Models\Product::$featuresRelationCalls);
    }

    private function generateTypes(): string
    {
        $outputDirectory = $this->fixtureRoot . '/resources/ts/puddleglum';

        config()->set('puddleglum.output', $outputDirectory);
        config()->set('puddleglum.namespace', 'Puddleglum');
        config()->set('puddleglum.models_namespace', 'Models');

        chdir($this->fixtureRoot);

        $this->artisan('puddleglum:generate')
            ->assertExitCode(0);

        return $outputDirectory;
    }

    private function createFixtureApp(): void
    {
        $sourceRoot = dirname(__DIR__) . '/Fixtures/LaravelApp';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = ltrim(str_replace($sourceRoot, '', $file->getPathname()), '/');
            $this->writeFixtureFile($relativePath, file_get_contents($file->getPathname()));
        }
    }

    private function registerFixtureAutoloader(): void
    {
        $this->appAutoloader = function (string $class): void {
            if (! str_starts_with($class, 'App\\')) {
                return;
            }

            $file = $this->fixtureRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        };

        spl_autoload_register($this->appAutoloader);
    }

    private function resetFixtureCounters(): void
    {
        \App\Models\Category::$productsRelationCalls = 0;
        \App\Models\Feature::$productRelationCalls = 0;
        \App\Models\Product::$categoryRelationCalls = 0;
        \App\Models\Product::$featuresRelationCalls = 0;
    }

    private function registerFixtureRoutes(): void
    {
        Route::middleware('api')->get('/products', [\App\Http\Controllers\ProductController::class, 'index']);
        Route::middleware('api')->post('/products', [\App\Http\Controllers\ProductController::class, 'store']);
        Route::middleware('api')->get('/products/{product}', [\App\Http\Controllers\ProductController::class, 'show']);
        Route::middleware('api')->delete('/products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy']);
        Route::middleware('api')->get('/products/archive', [\App\Http\Controllers\ProductControllerArchive::class, 'index']);
    }

    private function assertGeneratedTreeMatchesFixture(string $actualRoot): void
    {
        $expectedRoot = dirname(__DIR__) . '/Fixtures/Expected/puddleglum';
        $expectedFiles = $this->relativeFiles($expectedRoot);
        $actualFiles = $this->relativeFiles($actualRoot);

        $this->assertSame($expectedFiles, $actualFiles);

        foreach ($expectedFiles as $relativeFile) {
            $this->assertFileEquals(
                $expectedRoot . '/' . $relativeFile,
                $actualRoot . '/' . $relativeFile,
                "Generated file [{$relativeFile}] did not match the expected TypeScript output.",
            );
        }
    }

    /**
     * @return string[]
     */
    private function relativeFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }

        sort($files);

        return $files;
    }

    private function writeFixtureFile(string $relativePath, string $contents): void
    {
        $path = $this->fixtureRoot . '/' . $relativePath;
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create fixture directory [{$directory}].");
        }

        file_put_contents($path, $contents);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
