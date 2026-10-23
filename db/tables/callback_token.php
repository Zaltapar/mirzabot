<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        token varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
        payload text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        created_at varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        SQL,
];
