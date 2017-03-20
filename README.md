# Digitalquill Laravel REST instrumentary

## Why?

Everytime projects need a REST api to work with, it finished with lots of
duplicate code all over the place and what is worse, in multiple projects.

So to handle this problem, this repository was created.

For now it contains only one controller from which you can extend to get all
those standard REST functions.

Controller is highly customizible by overriding some functions.

Please refer to [source code](src/RestController.php) to see what you can do.

## Installation

    composer require staskjs/laravel-rest

Then register service provider in `config/app.php`:

    Staskjs\Rest\RestServiceProvider::class

Then just extend your controller from `\Staskjs\Rest\RestController`,
register `resource` route (`Route::resource`) pointing to your controller
and you are good to go!

You can also use our custom exception handler that will translate some laravel errors to json format
if request is made from ajax.

In `app/Exceptions/Handler.php` inherit handler from `\Staskjs\Rest\Exceptions\Handler`.

## Usage

You can add Form Request Validation to every request there is.

Simple override these parameters with class to use.

    protected $storeRequest = ModelStoreRequest::class;

    // Same with other requests, if needed

For simpler approach to validation, override `rules` method to return array of validation rules.
