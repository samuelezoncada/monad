<?php
/**
 * Monad app entry point.
 *
 * The engine lives in monad.php. Put routes, middleware and app services here;
 * this file is a launcher and should stay trivial.
 */
$app = require __DIR__ . '/monad.php';

// $app->addRoute('GET', '/', fn ($app) => $app->response->text('It works.'));

$app->dispatch();
