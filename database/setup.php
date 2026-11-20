<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';

$host = cvcarts_env('DB_HOST', '127.0.0.1') ?: '127.0.0.1';
$user = cvcarts_env('DB_USER', 'root') ?: 'root';
$pass = cvcarts_env('DB_PASSWORD');
$dbName = cvcarts_env('DB_NAME', 'cscqc_ecommerce') ?: 'cscqc_ecommerce';

try {
    $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
    $pdo->exec('USE `' . $dbName . '`;');

    $schema = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) NOT NULL,
            username VARCHAR(50) DEFAULT NULL UNIQUE,
            email VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('student','admin') NOT NULL DEFAULT 'student',
            security_question VARCHAR(255) DEFAULT NULL,
            security_question_id TINYINT UNSIGNED DEFAULT NULL,
            security_answer_hash VARCHAR(255) DEFAULT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            last_login DATETIME DEFAULT NULL,
            account_status VARCHAR(24) NOT NULL DEFAULT 'active',
            registration_status VARCHAR(24) NOT NULL DEFAULT 'completed',
            email_verified_at DATETIME DEFAULT NULL,
            last_login_at DATETIME DEFAULT NULL,
            password_changed_at DATETIME DEFAULT NULL,
            security_updated_at DATETIME DEFAULT NULL,
            auth_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            category VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            has_sizes TINYINT(1) NOT NULL DEFAULT 0,
            image_url VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            selected_size VARCHAR(50) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_cart_item (user_id, product_id, selected_size),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            reference_number VARCHAR(50) NOT NULL UNIQUE,
            total_amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'Unpaid',
            order_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            payment_reference VARCHAR(150) DEFAULT NULL,
            payment_proof_path VARCHAR(255) DEFAULT NULL,
            qr_token VARCHAR(255) DEFAULT NULL UNIQUE,
            qr_token_hash CHAR(64) DEFAULT NULL,
            qr_token_nonce CHAR(32) DEFAULT NULL,
            pickup_schedule_id INT DEFAULT NULL,
            claim_date DATETIME DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            cancellation_reason VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY orders_qr_token_hash_unique (qr_token_hash),
            KEY orders_ready_to_claim_idx (payment_status, order_status, verified_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            selected_size VARCHAR(50) DEFAULT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            related_order_id INT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (related_order_id) REFERENCES orders(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS admin_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    ];

    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }

    require_once __DIR__ . '/../includes/migrations.php';
    cvcarts_migrate($pdo);

    $productInsert = [
        ['TYPE B Uniform', 'Premium school uniform with breathable fabric.', 'Uniforms', 250.00, 50, 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80'],
        ['ID Lace', 'Official student identification lace accessory.', 'Accessories', 120.00, 100, 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80'],
        ['PE Uniform', 'Comfortable PE outfit for school activities.', 'Uniforms', 350.00, 30, 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=900&q=80'],
        ['CSCQC Notebook', 'Academic notebook with school branding.', 'Stationery', 85.00, 70, 'https://images.unsplash.com/photo-1481627834876-b7833e8f5570?auto=format&fit=crop&w=900&q=80'],
        ['Student Tote', 'Durable and stylish school tote bag.', 'Accessories', 260.00, 40, 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80']
    ];

    foreach ($productInsert as $product) {
        $existing = $pdo->prepare('SELECT id FROM products WHERE name = :name');
        $existing->execute(['name' => $product[0]]);
        if (!$existing->fetch()) {
            $pdo->prepare('INSERT INTO products (name, description, category, price, stock, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
                ->execute([$product[0], $product[1], $product[2], $product[3], $product[4], $product[5]]);
        }
    }

    if (PHP_SAPI === 'cli') {
        echo "Database and tables created successfully.\n";
        echo "Initialize the administrator with: php database/init_admin.php\n";
    }
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        fwrite(STDERR, 'Database setup failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    throw $e;
}
