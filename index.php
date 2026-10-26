<?php
/**
 * Monad app entry point.
 *
 * The engine lives in monad.php, which is never edited: put routes, middleware
 * and app services here.
 */
$app = require __DIR__ . '/monad.php';

// $app->addRoute('GET', '/', fn ($app) => $app->response->text('It works.'));

$app->dispatch();
