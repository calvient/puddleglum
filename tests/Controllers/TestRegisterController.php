<?php

namespace Calvient\Puddleglum\Tests\Controllers;

use App\Http\Controllers\Controller;
use Calvient\Puddleglum\Attributes\{GlumRequest, GlumResponse};
use Illuminate\Http\Request;

class TestRegisterController extends Controller
{
	#[GlumRequest(['name' => 'string', 'email' => 'string', 'password' => 'string'])]
	#[GlumResponse(['message' => 'string', 'user' => 'User'])]
	public function register(Request $request) {
	}
}
