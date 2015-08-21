<?php
// framework/index.php
require __DIR__.'/vendor/autoload.php';
var_dump(Pramnos\Framework\Base::class);
@error_reporting(E_ALL | E_WARNING | E_PARSE | E_NOTICE | E_DEPRECATED | E_STRICT);
        @ini_set('display_errors', 'On');
        @ini_set('log_errors', 'On');


Pramnos\Http\Request::get('test');