<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cache_key CHAR(32) NOT NULL,
        data MEDIUMTEXT NOT NULL,
        fetched_at INT(11) NOT NULL,
        UNIQUE KEY uniq_panelcache_key (cache_key)
        SQL,
    'columns' => [
        ['data', null, 'MEDIUMTEXT NOT NULL'],
        ['fetched_at', null, 'INT(11) NOT NULL'],
    ],
];
