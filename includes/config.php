<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function app_config(): SimpleXMLElement
{
    static $config = null;

    if ($config === null) {
        $path = app_config_path();
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

function app_config_path(): string
{
    $defaultPath = dirname(__DIR__) . '/config.xml';
    $configuredPath = env_value('APP_CONFIG_PATH');

    if ($configuredPath === null || $configuredPath === '') {
        return $defaultPath;
    }

    $dir = dirname($configuredPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar a pasta do APP_CONFIG_PATH.');
    }

    if (!file_exists($configuredPath) && file_exists($defaultPath)) {
        copy($defaultPath, $configuredPath);
    }

    return $configuredPath;
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
    $aivenDatabaseUrl = first_env_value([
        'AIVEN_DATABASE_URL',
        'DATABASE_URL',
    ]);

    if ($aivenDatabaseUrl) {
        return database_settings_from_url($aivenDatabaseUrl, 'DATABASE_URL');
    }

    $dbHost = first_env_value(['AIVEN_DB_HOST', 'DB_HOST']);
    if ($dbHost !== null && $dbHost !== '') {
        if (str_contains($dbHost, '://')) {
            return database_settings_from_url($dbHost, 'DB_HOST');
        }

        return [
            'host' => normalize_database_host($dbHost),
            'port' => first_env_value(['AIVEN_DB_PORT', 'DB_PORT'], normalize_database_port($dbHost, '3306')),
            'database' => first_env_value(['AIVEN_DB_NAME', 'DB_NAME'], 'salao_sammy'),
            'user' => first_env_value(['AIVEN_DB_USER', 'DB_USER'], 'root'),
            'password' => first_env_value(['AIVEN_DB_PASS', 'DB_PASS'], ''),
        ];
    }

    $railwayDatabaseUrl = first_env_value([
        'MYSQL_URL',
        'MYSQL_PUBLIC_URL',
        'RAILWAY_DATABASE_URL',
    ]);

    if ($railwayDatabaseUrl) {
        return database_settings_from_url($railwayDatabaseUrl, 'MYSQL_URL');
    }

    $mysqlHost = env_value('MYSQLHOST');
    if ($mysqlHost !== null && $mysqlHost !== '') {
        return [
            'host' => $mysqlHost,
            'port' => first_env_value(['MYSQLPORT'], '3306'),
            'database' => first_env_value(['MYSQLDATABASE', 'MYSQL_DATABASE'], 'railway'),
            'user' => first_env_value(['MYSQLUSER', 'MYSQL_USER'], 'root'),
            'password' => first_env_value(['MYSQLPASSWORD', 'MYSQL_PASSWORD'], ''),
        ];
    }

    return [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'salao_sammy',
        'user' => 'root',
        'password' => '',
    ];
}

function database_settings_from_url(string $databaseUrl, string $label): array
{
    $parts = parse_url($databaseUrl);
    if ($parts === false) {
        throw new RuntimeException($label . ' invalida.');
    }

    $database = trim(ltrim($parts['path'] ?? '', '/'));
    if (($parts['host'] ?? '') === '' || $database === '' || ($parts['user'] ?? '') === '') {
        throw new RuntimeException($label . ' incompleta. Informe usuario, host, porta e banco.');
    }

    return [
        'host' => normalize_database_host($parts['host'] ?? 'localhost'),
        'port' => (string)($parts['port'] ?? '3306'),
        'database' => $database,
        'user' => rawurldecode($parts['user'] ?? ''),
        'password' => rawurldecode($parts['pass'] ?? ''),
    ];
}

function normalize_database_host(string $host): string
{
    $host = trim($host);
    $host = preg_replace('/^mysql:\/\//i', '', $host) ?? $host;
    $host = preg_replace('/^https?:\/\//i', '', $host) ?? $host;

    if (str_contains($host, '@')) {
        $host = substr($host, strrpos($host, '@') + 1);
    }

    $host = explode(':', $host, 2)[0];

    return trim(explode('/', $host, 2)[0]);
}

function normalize_database_port(string $host, string $default): string
{
    $host = trim($host);
    $host = preg_replace('/^mysql:\/\//i', '', $host) ?? $host;
    $host = preg_replace('/^https?:\/\//i', '', $host) ?? $host;

    if (str_contains($host, '@')) {
        $host = substr($host, strrpos($host, '@') + 1);
    }

    $host = explode('/', $host, 2)[0];
    if (!str_contains($host, ':')) {
        return $default;
    }

    $port = trim(explode(':', $host, 2)[1]);
    return ctype_digit($port) ? $port : $default;
}

function upload_dir(): string
{
    $dir = env_value('UPLOAD_DIR', dirname(__DIR__) . '/assets/uploads') ?? dirname(__DIR__) . '/assets/uploads';
    return rtrim(str_replace('\\', '/', $dir), '/');
}

function upload_url(string $filename): string
{
    $prefix = env_value('UPLOAD_URL_PREFIX', 'assets/uploads') ?? 'assets/uploads';
    return rtrim($prefix, '/') . '/' . ltrim($filename, '/');
}
