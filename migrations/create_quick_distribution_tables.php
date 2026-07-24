<?php
// app/migrations/create_quick_distribution_tables.php
// Standalone script for CLI migration

require_once __DIR__ . '/../app/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];

echo "Connecting to database $db_name at $db_host as $db_user...\n";

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS quick_distributions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NOT NULL,
            distribution_point_id INT NOT NULL,
            distribution_date DATE NOT NULL,
            menu_name VARCHAR(255) NOT NULL,
            portion_count INT NOT NULL,
            nutrition_info JSON,
            notes TEXT,
            status ENUM('Terjadwal', 'Dikirim', 'Diterima', 'Dibatalkan') DEFAULT 'Terjadwal',
            delivery_time DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (organization_id),
            INDEX (distribution_date),
            FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (distribution_point_id) REFERENCES distribution_points(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS quick_distribution_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quick_distribution_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            caption TEXT,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quick_distribution_id) REFERENCES quick_distributions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $sql) {
        if ($conn->query($sql) === TRUE) {
            echo "Table created successfully (or already exists)\n";
        } else {
            echo "Error executing query: " . $conn->error . "\n";
        }
    }

    $conn->close();

} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>