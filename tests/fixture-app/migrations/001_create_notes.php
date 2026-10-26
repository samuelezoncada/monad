<?php

return [
    'up' => fn ($db) => $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)'),
    'down' => fn ($db) => $db->execute('DROP TABLE notes'),
];
