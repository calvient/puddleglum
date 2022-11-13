<?php

namespace Calvient\Puddleglum\Attributes;

#[\Attribute]
class GlumResponse
{
	public function __construct(public array $typescriptResponse)
	{
	}
}
