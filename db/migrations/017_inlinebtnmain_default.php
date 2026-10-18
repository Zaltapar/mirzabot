<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('setting')) {
        return;
    }
    if (!$schema->tableExists('schema_flags')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_flags (flag VARCHAR(100) PRIMARY KEY, applied_at INT NULL) " . Schema::DEFAULT_OPTIONS);
    }
    $stmt = $pdo->prepare("SELECT 1 FROM schema_flags WHERE flag = ?");
    $stmt->execute(['inlinebtnmain_oninline_default']);
    if ($stmt->fetchColumn() !== false) {
        return;
    }
    $pdo->exec("UPDATE setting SET inlinebtnmain = 'oninline' WHERE inlinebtnmain = 'offinline'");
    $insert = $pdo->prepare("INSERT INTO schema_flags (flag, applied_at) VALUES (?, ?)");
    $insert->execute(['inlinebtnmain_oninline_default', time()]);
};
