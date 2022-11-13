<?php

namespace Calvient\Puddleglum\Tests\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserLogin;
use Calvient\Puddleglum\Attributes\GlumResponse;

class TestLoginController extends Controller
{
	#[GlumResponse(['message' => 'string', 'user' => 'User'])]
	public function login(UserLogin $request) {
	}

	#[GlumResponse(['message' => 'string'])]
	public function logout()
	{
	}
}
