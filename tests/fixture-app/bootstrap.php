<?php

$app->addRoute('GET', '/', function ($app) {
    $count = (int) ($app->db->fetchOne('SELECT COUNT(*) AS c FROM notes')['c'] ?? 0);
    $app->response->render('html.home', ['count' => $count]);
});

$app->addCommand('notes:add', function ($app, array $args) {
    $app->db->insert('notes', ['body' => $args[0] ?? 'note']);
    echo "added\n";
});
