<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function app_config(): SimpleXMLElement
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/../config.xml';
        if (!file_exists($path)) {
            throw new RuntimeException('Arquivo config.xml não encontrado.');
        }

        $config = simplexml_load_file($path);
        if (!$config instanceof SimpleXMLElement) {
            throw new RuntimeException('Não foi possível ler o config.xml.');
        }
    }

    return $config;
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function database_settings(): array
{
    $databaseUrl = env_value('DATABASE_URL');
    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts === false) {
            throw new RuntimeException('DATABASE_URL inválida.');
        }

        return [
            'host' => $parts['host'] ?? 'localhost',
            'port' => (string)($parts['port'] ?? '3306'),
            'database' => ltrim($parts['path'] ?? '', '/'),
            'user' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
        ];
    }

    return [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => env_value('DB_PORT', '3306'),
        'database' => env_value('DB_NAME', 'salao_sammy'),
        'user' => env_value('DB_USER', 'root'),
        'password' => env_value('DB_PASS', ''),
    ];
}
