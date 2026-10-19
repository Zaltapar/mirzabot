<?php

return [
    'create' => <<<SQL
        id_user varchar(200) NOT NULL,
        kind varchar(50) NOT NULL,
        value varchar(200) NOT NULL,
        label varchar(300) NULL,
        counter INT(11) NOT NULL DEFAULT 0,
        last_used varchar(100) NULL,
        PRIMARY KEY (id_user, kind, value)
        SQL,
];
