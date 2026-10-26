<?php

return [
    'create' => <<<SQL
        rule_id INT UNSIGNED NOT NULL,
        id_user VARCHAR(500) NOT NULL,
        sent_at INT NOT NULL,
        PRIMARY KEY (rule_id, id_user),
        KEY idx_rule_sent (rule_id, sent_at)
        SQL,
];
