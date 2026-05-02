<?php
// PDO database singleton — one shared connection across the app

if (!defined('APP_ROOT')) die('Direct access not permitted');

class Database {
    private static ?PDO $instance = null;

    /**
     * Returns the shared PDO connection.
     * Creates it on first call, reuses on subsequent calls.
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST
                 . ';port=' . DB_PORT
                 . ';dbname=' . DB_NAME
                 . ';charset=utf8mb4';

            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }
}