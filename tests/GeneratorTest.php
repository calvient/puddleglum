<?php

namespace Calvient\Puddleglum\Tests;

use Calvient\Puddleglum\PuddleglumGenerator;

class GeneratorTest extends TestCase
{
	/** @test */
	public function it_works()
	{
		$output = @tempnam('/tmp', 'models.d.ts');

		$generator = new PuddleglumGenerator(output: $output);

		$generator->execute();

		$this->assertFileExists($output);

		$result = file_get_contents($output);

		$this->assertEquals(3, substr_count($result, 'interface'));
		$this->assertTrue(
			str_contains($result, 'sub_category?: Based.TypeScript.Tests.Models.Category | null;'),
		);
		$this->assertTrue(str_contains($result, 'products_count?: number | null;'));

		unlink($output);
	}
}
