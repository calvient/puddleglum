# Puddleglum

[![Latest Version on Packagist](https://img.shields.io/packagist/v/calvient/puddleglum.svg?style=flat-square)](https://packagist.org/packages/based/laravel-typescript)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/calvient/puddle-glum/run-tests?label=tests)](https://github.com/lepikhinb/laravel-typescript/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/calvient/puddleglum.svg?style=flat-square)](https://packagist.org/packages/based/laravel-typescript)

Puddleglum allows you to automatically generate TypeScript interfaces from your Laravel models and requests as well as
a typesafe Axios implementation for your API routes.

This package is based on lepikhinb/laravel-typescript. Special thanks for such an awesome package!

## Introduction

If you, like Calvient, have a Laravel backend with a TS front-end (be it React, Vue, or something else),
you might have noticed that you have to manually keep your TypeScript interfaces in sync with your Laravel models and
requests.
You might also have noticed that you have to manually keep your Axios implementation in sync with your Laravel routes.

Puddleglum is a Laravel package that solves these problems by automatically generating Typescript interfaces and a simple API implementation based on Axios.

## Installation

**Laravel 8 and PHP 8 are required.**
You can install the package via composer:

```bash
composer require calvient/puddleglum
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Calvient\Puddleglum\PuddleglumServiceProvider" --tag="puddleglum-config"
```

This is the contents of the published config file:

```php
return [
    'output' => resource_path('js/models.d.ts'),
];
```

## Usage

Generate TypeScript interfaces.

```bash
php artisan puddleglum:generate
```

### Defining your requests

In Laravel, you might define your requests from the command line like this:

```bash
php artisan make:request StoreUser
```

If you do this (i.e., your requests extend FormRequest), Puddleglum will automatically generate TypeScript interfaces
for your requests. The program reads the rules from your FormRequest and generates TypeScript interfaces for your
requests.

For example:

```php 
class UserLogin extends FormRequest
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
				Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised(),
			],
		];
	}
}

```

Becomes:
```typescript
export interface UserLogin {
	email: string;
	password: string;
}
```

If you don't want to define a FormRequest (e.g., you want to use a request validator directly in your controller),
you can use the GlumRequest attribute before your controller.

Example;
```php
#[GlumRequest(['name' => 'string', 'email' => 'string', 'password' => 'string'])]
public function register(Request $request) {
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => Password::min(8)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised(),
    ]);
}
```

### Defining Responses

Defining a response is a bit tricker, because PHP lacks support for generics. However, Puddleglum can still generate
TypeScript interfaces for your responses.

We accomplish this by using a PHP attribute.

Example:

```php
#[GlumResponse(['user' => 'User', 'message' => 'string'])]
public function show(User $user): User
{
    return response()->json([
        'user' => $user,
        'message' => 'Hello, world!',
    ]);
}
```

## Using the Puddleglum Output in your TypeScript code
Import the Puddleglum client into your TypeScript code:

```typescript
import {Puddleglum} from '../../puddleglum';
```

Then, you can use the Puddleglum client to make API calls:

```typescript
const login = async (email: string, password: string) => {
    const reply = await Puddleglum.Auth.LoginController.login({email, password});
    setUser(reply.data.user);
};
```

The API client mirrors the PHP namespace.  So, in the example 
above, the login controller is at `\App\Http\Controllers\Auth` 
and the method is named `login`.

You can also import various models and requests.
```typescript
import {User} from '../../puddleglum';

const [user, setUser] = React.useState<User>();
```



## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
