<?php

// The CLI loads the app's bootstrap.php before the suite, so the same routes and
// commands used by public/index.php are already registered here.
$test->beforeEach(fn ($app) => $app->db->execute('DELETE FROM notes'));

$test->it("fixture: boots from the app root", function ($app, $test) {
    $test->expect($app->root)->toEqual(__DIR__);
});

$test->it("fixture: migrations created the notes table", function ($app, $test) {
    $count = $app->db->fetchOne('SELECT COUNT(*) AS c FROM notes');
    $test->expect((int) $count['c'])->toEqual(0);
});

$test->it("fixture: renders through a real route", function ($app, $test) {
    $res = $test->request('GET', '/');
    $test->expect($res->statusCode)->toEqual(200);
    $test->expect($res->body)->toContain('fixture ok');
    $test->expect($res->body)->toContain('0 notes');
});
