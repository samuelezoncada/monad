<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Monad\App::boot(dirname(__DIR__));

require dirname(__DIR__) . '/bootstrap.php';

$app->dispatch();
