<?php

function cvcarts_column_exists(PDO $db, string $table, string $column): bool {
    $statement = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $statement->execute(['table_name' => $table, 'column_name' => $column]);
    return (bool)$statement->fetchColumn();
}

function cvcarts_index_exists(PDO $db, string $table, string $index): bool {
    $statement = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name');
    $statement->execute(['table_name' => $table, 'index_name' => $index]);
    return (bool)$statement->fetchColumn();
}

function cvcarts_migrate_auth_security(PDO $db): void {
    $migrationKey = 'auth_security_20261115_v2';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        $columns = [
            'username' => 'VARCHAR(50) NULL',
            'security_question_id' => 'TINYINT UNSIGNED DEFAULT NULL',
            'account_status' => "VARCHAR(24) NOT NULL DEFAULT 'active'",
            'registration_status' => "VARCHAR(24) NOT NULL DEFAULT 'completed'",
            'email_verified_at' => 'DATETIME DEFAULT NULL',
            'last_login_at' => 'DATETIME DEFAULT NULL',
            'password_changed_at' => 'DATETIME DEFAULT NULL',
            'security_updated_at' => 'DATETIME DEFAULT NULL',
            'auth_version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
        ];

        foreach ($columns as $column => $definition) {
            if (!cvcarts_column_exists($db, 'users', $column)) {
                $db->exec('ALTER TABLE users ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        $usernameIndex = $db->query("SHOW INDEX FROM users WHERE Column_name = 'username' AND Non_unique = 0");
        if (!$usernameIndex->fetch(PDO::FETCH_ASSOC)) {
            $db->exec('ALTER TABLE users ADD UNIQUE KEY users_username_unique (username)');
        }

        $db->exec("UPDATE users SET account_status = 'pending_verification', registration_status = 'pending', is_active = 0 WHERE role = 'student' AND is_verified = 0 AND registration_status = 'completed'");
        $db->exec("UPDATE users SET account_status = 'active', registration_status = 'completed', email_verified_at = COALESCE(email_verified_at, created_at) WHERE is_verified = 1");
        $db->exec('UPDATE users SET last_login_at = last_login WHERE last_login_at IS NULL AND last_login IS NOT NULL');

        $db->exec("CREATE TABLE IF NOT EXISTS account_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_type VARCHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY account_tokens_hash_idx (token_hash, token_type, user_id),
            KEY account_tokens_user_type_idx (user_id, token_type, created_at),
            KEY account_tokens_expiry_idx (token_type, expires_at, used_at),
            CONSTRAINT account_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        if (cvcarts_index_exists($db, 'account_tokens', 'account_tokens_hash_unique')) {
            $db->exec('ALTER TABLE account_tokens DROP INDEX account_tokens_hash_unique');
        }
        if (!cvcarts_index_exists($db, 'account_tokens', 'account_tokens_hash_idx')) {
            $db->exec('ALTER TABLE account_tokens ADD KEY account_tokens_hash_idx (token_hash, token_type, user_id)');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(32) NOT NULL,
            identifier_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            succeeded TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY auth_attempts_identifier_idx (action, identifier_hash, created_at),
            KEY auth_attempts_ip_idx (action, ip_hash, created_at)
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS email_delivery_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purpose VARCHAR(40) NOT NULL,
            recipient_hash CHAR(64) NOT NULL,
            delivery_status ENUM('accepted','failed') NOT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY email_delivery_purpose_idx (purpose, created_at),
            KEY email_delivery_recipient_idx (recipient_hash, created_at)
        ) ENGINE=InnoDB");

        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_ecommerce_workflows(PDO $db): void {
    $migrationKey = 'payments_orders_20261115_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the order workflow migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        $columns = [
            'scheduled_pickup_at' => 'DATETIME DEFAULT NULL',
            'claimed_by_admin_id' => 'INT DEFAULT NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!cvcarts_column_exists($db, 'orders', $column)) {
                $db->exec('ALTER TABLE orders ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
        if (!cvcarts_index_exists($db, 'orders', 'orders_claimed_by_admin_idx')) {
            $db->exec('ALTER TABLE orders ADD KEY orders_claimed_by_admin_idx (claimed_by_admin_id)');
        }
        $claimForeignKey = $db->prepare("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'orders_claimed_by_admin_fk'");
        $claimForeignKey->execute();
        if (!$claimForeignKey->fetchColumn()) {
            $db->exec('ALTER TABLE orders ADD CONSTRAINT orders_claimed_by_admin_fk FOREIGN KEY (claimed_by_admin_id) REFERENCES users(id) ON DELETE SET NULL');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'Pending Verification',
            transaction_reference VARCHAR(150) DEFAULT NULL,
            proof_path VARCHAR(255) DEFAULT NULL,
            rejection_reason VARCHAR(500) DEFAULT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by_admin_id INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY payments_order_status_idx (order_id, status, created_at),
            KEY payments_review_idx (status, submitted_at),
            CONSTRAINT payments_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT payments_reviewed_by_admin_fk FOREIGN KEY (reviewed_by_admin_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS order_status_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            old_status VARCHAR(30) DEFAULT NULL,
            new_status VARCHAR(30) NOT NULL,
            changed_by_user_id INT DEFAULT NULL,
            reason VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY order_status_history_order_idx (order_id, created_at),
            CONSTRAINT order_status_history_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT order_status_history_user_fk FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $db->exec("INSERT INTO payments (order_id, payment_method, amount, status, transaction_reference, proof_path, submitted_at, paid_at)
            SELECT o.id, o.payment_method, o.total_amount, o.payment_status, o.payment_reference, o.payment_proof_path, o.created_at,
                CASE WHEN o.payment_status = 'Paid' THEN o.updated_at ELSE NULL END
            FROM orders o
            WHERE NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)");
        $db->exec("INSERT INTO order_status_history (order_id, old_status, new_status, reason, created_at)
            SELECT o.id, NULL, o.order_status, 'Status imported during workflow migration', o.created_at
            FROM orders o
            WHERE NOT EXISTS (SELECT 1 FROM order_status_history h WHERE h.order_id = o.id)");

        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_order_claim_qr(PDO $db): void {
    $migrationKey = 'order_claim_qr_20261116_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the order claim migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        if (!cvcarts_column_exists($db, 'orders', 'qr_token_hash')) {
            $db->exec('ALTER TABLE orders ADD COLUMN qr_token_hash CHAR(64) DEFAULT NULL');
        }
        if (!cvcarts_column_exists($db, 'orders', 'qr_token_nonce')) {
            $db->exec('ALTER TABLE orders ADD COLUMN qr_token_nonce CHAR(32) DEFAULT NULL');
        }
        if (!cvcarts_index_exists($db, 'orders', 'orders_qr_token_hash_unique')) {
            $db->exec('ALTER TABLE orders ADD UNIQUE KEY orders_qr_token_hash_unique (qr_token_hash)');
        }

        $db->exec("UPDATE orders SET qr_token_hash = SHA2(qr_token, 256)
            WHERE qr_token_hash IS NULL AND qr_token IS NOT NULL AND qr_token <> ''");
        $db->exec('ALTER TABLE orders MODIFY qr_token VARCHAR(255) DEFAULT NULL');
        $db->exec("UPDATE orders SET order_status = 'Claimed' WHERE order_status = 'Already Claimed'");
        $db->exec("UPDATE order_status_history SET old_status = 'Claimed' WHERE old_status = 'Already Claimed'");
        $db->exec("UPDATE order_status_history SET new_status = 'Claimed' WHERE new_status = 'Already Claimed'");
        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_order_verification_dates(PDO $db): void {
    $migrationKey = 'order_verification_dates_20261117_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the order verification date migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        foreach ([
            'verified_at' => 'DATETIME DEFAULT NULL',
            'cancelled_at' => 'DATETIME DEFAULT NULL',
            'cancellation_reason' => 'VARCHAR(500) DEFAULT NULL',
        ] as $column => $definition) {
            if (!cvcarts_column_exists($db, 'orders', $column)) {
                $db->exec('ALTER TABLE orders ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
        if (!cvcarts_index_exists($db, 'orders', 'orders_ready_to_claim_idx')) {
            $db->exec('ALTER TABLE orders ADD KEY orders_ready_to_claim_idx (payment_status, order_status, verified_at)');
        }

        $db->exec("UPDATE orders o SET verified_at = COALESCE(
            (SELECT MAX(COALESCE(p.reviewed_at, p.paid_at)) FROM payments p WHERE p.order_id = o.id AND p.status = 'Paid'),
            o.updated_at
        ) WHERE o.payment_status = 'Paid' AND o.verified_at IS NULL");
        $db->exec("UPDATE orders o SET cancelled_at = COALESCE(
            (SELECT MAX(h.created_at) FROM order_status_history h WHERE h.order_id = o.id AND h.new_status = 'Cancelled'),
            o.updated_at
        ) WHERE o.order_status = 'Cancelled' AND o.cancelled_at IS NULL");

        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_product_and_pickup_options(PDO $db): void {
    $migrationKey = 'product_sizes_pickup_slots_20261116_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the product and pickup migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        if (!cvcarts_column_exists($db, 'products', 'has_sizes')) {
            $db->exec('ALTER TABLE products ADD COLUMN has_sizes TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!cvcarts_column_exists($db, 'orders', 'pickup_schedule_id')) {
            $db->exec('ALTER TABLE orders ADD COLUMN pickup_schedule_id INT DEFAULT NULL');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS product_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            size VARCHAR(50) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY product_sizes_product_size_unique (product_id, size),
            KEY product_sizes_available_idx (product_id, is_active, stock),
            CONSTRAINT product_sizes_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS pickup_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pickup_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            capacity INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_admin_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY pickup_schedules_available_idx (is_active, pickup_date, start_time),
            CONSTRAINT pickup_schedules_admin_fk FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            selected_size VARCHAR(50) DEFAULT NULL,
            order_id INT DEFAULT NULL,
            admin_id INT DEFAULT NULL,
            quantity_change INT NOT NULL,
            reason VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY inventory_movements_product_idx (product_id, created_at),
            KEY inventory_movements_order_idx (order_id, created_at),
            CONSTRAINT inventory_movements_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT inventory_movements_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            CONSTRAINT inventory_movements_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        if (!cvcarts_index_exists($db, 'orders', 'orders_pickup_schedule_idx')) {
            $db->exec('ALTER TABLE orders ADD KEY orders_pickup_schedule_idx (pickup_schedule_id)');
        }
        $pickupForeignKey = $db->prepare("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'orders_pickup_schedule_fk'");
        $pickupForeignKey->execute();
        if (!$pickupForeignKey->fetchColumn()) {
            $db->exec('ALTER TABLE orders ADD CONSTRAINT orders_pickup_schedule_fk FOREIGN KEY (pickup_schedule_id) REFERENCES pickup_schedules(id) ON DELETE SET NULL');
        }

        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_product_soft_deletion(PDO $db): void {
    $migrationKey = 'product_soft_deletion_20261121_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the product deletion migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        if (!cvcarts_column_exists($db, 'products', 'deleted_at')) {
            $db->exec('ALTER TABLE products ADD COLUMN deleted_at DATETIME DEFAULT NULL');
        }
        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_product_barcode(PDO $db): void {
    $migrationKey = 'product_barcode_20261121_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) return;

    if (!cvcarts_column_exists($db, 'products', 'barcode')) {
        $db->exec('ALTER TABLE products ADD COLUMN barcode VARCHAR(64) DEFAULT NULL');
    }
    $index = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'products_barcode_idx' LIMIT 1");
    $index->execute();
    if (!$index->fetchColumn()) {
        $db->exec('CREATE INDEX products_barcode_idx ON products (barcode)');
    }
    $db->prepare('INSERT IGNORE INTO schema_migrations (migration_key) VALUES (:migration_key)')
        ->execute(['migration_key' => $migrationKey]);
}

function cvcarts_migrate_student_profile_images(PDO $db): void {
    $migrationKey = 'student_profile_images_20261117_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the student profile image migration lock.');
    }

    try {
        $check->execute(['migration_key' => $migrationKey]);
        if ($check->fetchColumn()) {
            return;
        }

        if (!cvcarts_column_exists($db, 'users', 'profile_image')) {
            $db->exec('ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER email');
        }

        $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
            ->execute(['migration_key' => $migrationKey]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_disable_legacy_admin(PDO $db): void {
    $migrationKey = 'disable_legacy_seeded_admin_20261117_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $lockName = 'cvcarts_' . $migrationKey;
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the legacy administrator migration lock.');
    }

    try {
        $db->beginTransaction();
        try {
            $check->execute(['migration_key' => $migrationKey]);
            if (!$check->fetchColumn()) {
                $db->prepare("UPDATE users SET is_active = 0, account_status = 'inactive' WHERE role = 'admin' AND username = 'admin' AND email = 'admin123@cscqc.edu.ph'")
                    ->execute();
                $db->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)')
                    ->execute(['migration_key' => $migrationKey]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function cvcarts_migrate_realtime_events(PDO $db): void {
    $migrationKey = 'realtime_events_20261120_v1';
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS realtime_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(64) NOT NULL,
        audience ENUM('admin','student','public') NOT NULL,
        recipient_user_id INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY realtime_events_created_idx (created_at),
        KEY realtime_events_recipient_idx (audience, recipient_user_id, id)
    ) ENGINE=InnoDB");
    $db->prepare('INSERT IGNORE INTO schema_migrations (migration_key) VALUES (:migration_key)')
        ->execute(['migration_key' => $migrationKey]);
}

function cvcarts_migrate(PDO $db): void {
    $migrationKeys = [
        'auth_security_20261115_v2',
        'disable_legacy_seeded_admin_20261117_v1',
        'payments_orders_20261115_v1',
        'order_claim_qr_20261116_v1',
        'order_verification_dates_20261117_v1',
        'product_sizes_pickup_slots_20261116_v1',
        'student_profile_images_20261117_v1',
        'product_barcode_20261121_v1',
        'realtime_events_20261120_v1',
        'product_soft_deletion_20261121_v1',
    ];
    $placeholders = implode(', ', array_fill(0, count($migrationKeys), '?'));

    try {
        $check = $db->prepare('SELECT COUNT(DISTINCT migration_key) FROM schema_migrations WHERE migration_key IN (' . $placeholders . ')');
        $check->execute($migrationKeys);
        if ((int)$check->fetchColumn() === count($migrationKeys)) {
            return;
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1146) {
            throw $e;
        }
    }

    cvcarts_migrate_auth_security($db);
    cvcarts_migrate_disable_legacy_admin($db);
    cvcarts_migrate_realtime_events($db);
    cvcarts_migrate_ecommerce_workflows($db);
    cvcarts_migrate_order_claim_qr($db);
    cvcarts_migrate_order_verification_dates($db);
    cvcarts_migrate_product_and_pickup_options($db);
    cvcarts_migrate_product_soft_deletion($db);
    cvcarts_migrate_student_profile_images($db);
    cvcarts_migrate_product_barcode($db);
}
