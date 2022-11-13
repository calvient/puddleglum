<?php

namespace Calvient\Puddleglum;

use Calvient\Puddleglum\Commands\PuddleglumGenerateCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PuddleglumServiceProvider extends PackageServiceProvider
{
	public function configurePackage(Package $package): void
	{
		$package
			->name('puddleglum')
			->hasConfigFile('puddleglum')
			->hasCommand(PuddleglumGenerateCommand::class);
	}
}
