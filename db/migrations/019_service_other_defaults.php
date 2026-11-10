<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('service_other')) {
        return;
    }
    $columnInfo = static function (string $table, string $column) use ($pdo) {
        $stmt = $pdo->prepare("SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };
    $run = static function (string $scope, string $sql) use ($pdo): void {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log("[db:migration:service_other_defaults:$scope] " . $e->getMessage());
        }
    };

    $status = $columnInfo('service_other', 'status');
    if ($status && (string) ($status['COLUMN_DEFAULT'] ?? '') !== '') {
        $run('status_default', "ALTER TABLE `service_other` MODIFY `status` VARCHAR(200) NOT NULL DEFAULT ''");
    }

    $output = $columnInfo('service_other', 'output');
    if ($output && strtoupper((string) ($output['IS_NULLABLE'] ?? '')) !== 'YES') {
        // TEXT NOT NULL with no default makes every insert that omits `output`
        // fail under STRICT_TRANS_TABLES; make it nullable instead.
        $run('output_nullable', "ALTER TABLE `service_other` MODIFY `output` TEXT NULL");
    }
};
