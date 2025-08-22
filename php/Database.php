<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database {
    private PDO $pdo;

    public function __construct() {
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        // Wenn ein Socket-Pfad gesetzt ist, bevorzugen wir den Unix-Socket.
        if (!empty($_ENV['DB_SOCKET'])) {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $_ENV['DB_SOCKET'],
                $_ENV['DB_DATABASE'],
                $charset
            );
        } else {
            // Fallback: TCP/IP (funktioniert mit Hostname, 127.0.0.1 oder localhost)
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = (string)($_ENV['DB_PORT'] ?? '3306');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $port,
                $_ENV['DB_DATABASE'],
                $charset
            );
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $this->pdo = new PDO(
            $dsn,
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $options
        );

        // Collation nur setzen, wenn ausdrücklich angegeben (vermeidet Host-spezifische Fehler)
        if (!empty($_ENV['DB_COLLATION'])) {
            $this->pdo->exec('SET NAMES ' . $charset . ' COLLATE ' . $_ENV['DB_COLLATION']);
        }
    }

    public function pdo(): PDO {
        return $this->pdo;
    }
}
