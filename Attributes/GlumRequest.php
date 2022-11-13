<?php

namespace Calvient\Puddleglum\Attributes;

#[\Attribute]
class GlumRequest
{
	public function __construct(public array $typescriptRequest)
	{
	}
}
