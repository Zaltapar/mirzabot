<?php

return [
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(500) NOT NULL,
        username varchar(1000) NOT NULL,
        panel_name varchar(2000) NULL,
        sub_username varchar(300) NULL,
        sub_url varchar(2000) NULL,
        status varchar(50) NOT NULL DEFAULT 'pending',
        remaining_traffic varchar(100) NULL,
        remaining_days varchar(100) NULL,
        decided_by varchar(100) NULL,
        decided_at INT NOT NULL DEFAULT 0,
        note varchar(500) NULL,
        created_at INT NOT NULL DEFAULT 0,
        updated_at INT NOT NULL DEFAULT 0
        SQL,
    'columns' => [
        ['panel_name', null, 'VARCHAR(2000)'],
        ['sub_username', null, 'VARCHAR(300)'],
        ['sub_url', null, 'VARCHAR(2000)'],
        ['status', 'pending', 'VARCHAR(50)'],
        ['remaining_traffic', null, 'VARCHAR(100)'],
        ['remaining_days', null, 'VARCHAR(100)'],
        ['decided_by', null, 'VARCHAR(100)'],
        ['decided_at', '0', 'INT'],
        ['note', null, 'VARCHAR(500)'],
        ['created_at', '0', 'INT'],
        ['updated_at', '0', 'INT'],
    ],
];
