<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_br(float|string $value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function whatsapp_digits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits !== '' && !str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
    }

    return $digits;
}

function whatsapp_link(string $phone): string
{
    return 'https://wa.me/' . whatsapp_digits($phone);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ?page=login');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Acesso permitido apenas para administradores.');
    }
}

function redirect_with(string $page, string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: ?page=' . urlencode($page));
    exit;
}

function flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Token de segurança inválido.');
    }
}

function schedule_times(): array
{
    $config = app_config();
    $start = DateTime::createFromFormat('H:i', (string)$config->schedule->start);
    $end = DateTime::createFromFormat('H:i', (string)$config->schedule->end);
    $interval = max(15, (int)$config->schedule->interval);
    $times = [];

    if (!$start || !$end) {
        return ['08:00', '08:30', '09:00', '09:30', '10:00'];
    }

    while ($start < $end) {
        $times[] = $start->format('H:i');
        $start->modify("+{$interval} minutes");
    }

    return $times;
}

function selected(string $actual, string $expected): string
{
    return $actual === $expected ? 'selected' : '';
}
