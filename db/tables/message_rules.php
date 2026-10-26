<?php

return [
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        segment VARCHAR(50) NOT NULL,
        segment_param INT NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        button VARCHAR(50) NOT NULL DEFAULT 'none',
        frequency VARCHAR(20) NOT NULL DEFAULT 'once',
        active TINYINT(1) NOT NULL DEFAULT 1,
        last_run INT NOT NULL DEFAULT 0,
        last_count INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL DEFAULT 0,
        updated_at INT NOT NULL DEFAULT 0,
        SQL,
];
