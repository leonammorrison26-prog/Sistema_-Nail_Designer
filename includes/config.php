<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function app_config(): SimpleXMLElement
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/../config.xml';
        if (!file_exists($path)) {
            throw new RuntimeException('Arquivo config.xml nao encontrado.');
        }

        $config = simplexml_load_file($path);
        if (!$config instanceof SimpleXMLElement) {
            throw new RuntimeException('Nao foi possivel ler o config.xml.');
        }
    }

    return $config;
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $value = trim($value);
    if (
        strlen($value) >= 2
        && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
    ) {
        $value = substr($value, 1, -1);
    }

    return trim($value);
}

function first_env_value(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = env_value($key);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
}

function database_settings(): array
{
    $databaseUrl = first_env_value([
        'DATABASE_URL',
        'MYSQL_URL',
        'MYSQL_PUBLIC_URL',
        'RAILWAY_DATABASE_URL',
    ]);

    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts === false) {
            throw new RuntimeException('DATABASE_URL invalida.');
        }

        return [
            'host' => trim($parts['host'] ?? 'localhost'),
            'port' => (string)($parts['port'] ?? '3306'),
            'database' => trim(ltrim($parts['path'] ?? '', '/')),
            'user' => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
        ];
    }

    return [
        'host' => first_env_value(['MYSQLHOST', 'DB_HOST'], '127.0.0.1'),
        'port' => first_env_value(['MYSQLPORT', 'DB_PORT'], '3306'),
        'database' => first_env_value(['MYSQLDATABASE', 'MYSQL_DATABASE', 'DB_NAME'], 'salao_sammy'),
        'user' => first_env_value(['MYSQLUSER', 'MYSQL_USER', 'DB_USER'], 'root'),
        'password' => first_env_value(['MYSQLPASSWORD', 'MYSQL_PASSWORD', 'DB_PASS'], ''),
    ];
}
