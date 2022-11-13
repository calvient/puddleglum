<?php

namespace Calvient\Puddleglum\Tests\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class TestUserLogin extends FormRequest
{
	public function authorize()
	{
		return true;
	}

	public function rules()
	{
		return [
			'email' => 'required|email',
			'password' => [
				'required',
				Password::min(8)
					->mixedCase()
					->letters()
					->numbers()
					->symbols()
					->uncompromised(),
			],
		];
	}
}
