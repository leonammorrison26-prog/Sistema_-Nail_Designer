<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $settings = database_settings();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $settings['host'],
        $settings['port'],
        $settings['database']
    );

    $pdo = new PDO($dsn, $settings['user'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(160) NOT NULL UNIQUE,
            phone VARCHAR(60) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','manicure') NOT NULL DEFAULT 'manicure',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $columnExists = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
    if (!$columnExists) {
        $pdo->exec('ALTER TABLE users ADD phone VARCHAR(60) NULL AFTER email');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration_minutes INT NOT NULL DEFAULT 30,
            image_url VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(120) NOT NULL,
            client_phone VARCHAR(60) NOT NULL,
            client_email VARCHAR(160) NULL,
            service_id INT NOT NULL,
            manicure_id INT NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            status ENUM('marcado','confirmado','concluido','cancelado') NOT NULL DEFAULT 'marcado',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_appointments_service FOREIGN KEY (service_id) REFERENCES services(id),
            CONSTRAINT fk_appointments_manicure FOREIGN KEY (manicure_id) REFERENCES users(id),
            INDEX idx_appointments_slot (manicure_id, appointment_date, appointment_time, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS manicure_availability (
            id INT AUTO_INCREMENT PRIMARY KEY,
            manicure_id INT NOT NULL,
            available_date DATE NOT NULL,
            available_time TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_availability_manicure FOREIGN KEY (manicure_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_manicure_slot (manicure_id, available_date, available_time),
            INDEX idx_availability_lookup (manicure_id, available_date, available_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS marketing_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_id INT NULL,
            channel ENUM('instagram','whatsapp_status','both') NOT NULL DEFAULT 'both',
            caption TEXT NOT NULL,
            image_url VARCHAR(500) NULL,
            scheduled_for DATETIME NULL,
            status ENUM('rascunho','agendado','publicado') NOT NULL DEFAULT 'rascunho',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_marketing_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
            INDEX idx_marketing_schedule (status, scheduled_for)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    seed_initial_data($pdo);
}

function seed_initial_data(PDO $pdo): void
{
    $usersCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($usersCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            'Administrador',
            'admin',
            password_hash('123456', PASSWORD_DEFAULT),
            'admin',
        ]);
        $stmt->execute([
            'Sammy',
            'sammy@sammy.com',
            password_hash('sammy123', PASSWORD_DEFAULT),
            'manicure',
        ]);
    }

    $servicesCount = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
    if ($servicesCount === 0) {
        $stmt = $pdo->prepare('
            INSERT INTO services (name, description, price, duration_minutes, image_url, active)
            VALUES (?, ?, ?, ?, ?, 1)
        ');

        $services = [
            ['Alongamento na tips', 'Gel.', 115.00, 120, 'https://images.unsplash.com/photo-1632345031435-8727f6897d53?auto=format&fit=crop&w=900&q=80'],
            ['Postiça realista', 'Gel.', 95.00, 90, 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=900&q=80'],
            ['Banho em gel', 'Gel.', 95.00, 90, 'https://images.unsplash.com/photo-1610992015732-2449b76344bc?auto=format&fit=crop&w=900&q=80'],
            ['Blindagem+esmaltação em gel', 'Gel.', 55.00, 60, 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=900&q=80'],
            ['Pé', 'Tradicional.', 35.00, 40, 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=900&q=80'],
            ['Mão', 'Tradicional.', 30.00, 40, 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=900&q=80'],
            ['Pé e mão', 'Tradicional.', 55.00, 80, 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=900&q=80'],
            ['SPA dos pés', 'SPA.', 80.00, 60, 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=900&q=80'],
            ['SPA dos pés+cutilagem e esmaltação', 'SPA.', 110.00, 90, 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=900&q=80'],
        ];

        foreach ($services as $service) {
            $stmt->execute($service);
        }
    }
}
