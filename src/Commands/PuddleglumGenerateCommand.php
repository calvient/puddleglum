<?php

namespace Calvient\Puddleglum\Commands;

use Calvient\Puddleglum\PuddleglumGenerator;
use Illuminate\Console\Command;

class PuddleglumGenerateCommand extends Command
{
	public $signature = 'puddleglum:generate';

	public $description = 'Generate a typescript based API client';

	public function handle()
	{
		$outputFile = config('puddleglum.output', resource_path('ts/puddleglum'));
		$generator = new PuddleglumGenerator(output: $outputFile);
		$generator->execute();

		$this->comment($outputFile . ' has been updated!');
	}
}
