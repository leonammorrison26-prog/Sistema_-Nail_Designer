<?php
declare(strict_types=1);

function appointment_notification_message(array $appointment): string
{
    $date = date('d/m/Y', strtotime($appointment['appointment_date']));
    $time = substr((string)$appointment['appointment_time'], 0, 5);
    $notes = trim((string)($appointment['notes'] ?? ''));

    $message = "Novo agendamento\n";
    $message .= "Cliente: {$appointment['client_name']}\n";
    $message .= "WhatsApp: {$appointment['client_phone']}\n";
    $message .= "Serviço: {$appointment['service_name']}\n";
    $message .= "Data: {$date} às {$time}\n";

    if ($notes !== '') {
        $message .= "Observações: {$notes}\n";
    }

    return $message;
}

function calendar_escape(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\;', $value);
    $value = str_replace(',', '\,', $value);
    return str_replace(["\r\n", "\r", "\n"], '\n', $value);
}

function appointment_calendar_invite(array $appointment): string
{
    $timezone = getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo';
    $tz = new DateTimeZone($timezone);
    $start = new DateTime($appointment['appointment_date'] . ' ' . $appointment['appointment_time'], $tz);
    $end = clone $start;
    $end->modify('+' . max(15, (int)($appointment['duration_minutes'] ?? 30)) . ' minutes');
    $stamp = new DateTime('now', new DateTimeZone('UTC'));
    $uid = 'appointment-' . ($appointment['appointment_id'] ?? bin2hex(random_bytes(6))) . '@samara-eduarda-nail-designer';
    $summary = 'Atendimento: ' . (string)$appointment['service_name'];
    $description = appointment_notification_message($appointment);

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Samara Eduarda Nail Designer//Agenda//PT-BR',
        'CALSCALE:GREGORIAN',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . $stamp->format('Ymd\THis\Z'),
        'DTSTART;TZID=' . $timezone . ':' . $start->format('Ymd\THis'),
        'DTEND;TZID=' . $timezone . ':' . $end->format('Ymd\THis'),
        'SUMMARY:' . calendar_escape($summary),
        'DESCRIPTION:' . calendar_escape($description),
        'STATUS:CONFIRMED',
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    return implode("\r\n", $lines) . "\r\n";
}

function send_manicure_email(array $appointment, string $message): bool
{
    $email = trim((string)($appointment['manicure_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $from = getenv('NOTIFY_FROM_EMAIL') ?: 'no-reply@localhost';
    $subject = mb_encode_mimeheader('Novo agendamento - Samara Eduarda Nail Designer', 'UTF-8');
    $boundary = 'agenda_' . bin2hex(random_bytes(12));
    $calendar = appointment_calendar_invite($appointment);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $message . "\r\n";
    $body .= "\r\n--{$boundary}\r\n";
    $body .= "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"agendamento.ics\"\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n";
    $body .= "Content-Disposition: attachment; filename=\"agendamento.ics\"\r\n\r\n";
    $body .= $calendar;
    $body .= "\r\n--{$boundary}--\r\n";
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'From: ' . $from,
    ];

    return mail($email, $subject, $body, implode("\r\n", $headers));
}

function send_manicure_whatsapp(array $appointment, string $message): bool
{
    $token = getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
    $phoneNumberId = getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
    $to = whatsapp_digits((string)($appointment['manicure_phone'] ?? ''));

    if ($token === '' || $phoneNumberId === '' || $to === '') {
        return false;
    }

    $url = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message,
        ],
    ];

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 10,
    ]);

    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $status >= 200 && $status < 300;
}

function notify_manicure_new_appointment(PDO $pdo, int $appointmentId): void
{
    $stmt = $pdo->prepare("
        SELECT
            a.id AS appointment_id,
            a.client_name,
            a.client_phone,
            a.appointment_date,
            a.appointment_time,
            a.notes,
            s.name AS service_name,
            s.duration_minutes,
            u.email AS manicure_email,
            u.phone AS manicure_phone
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        JOIN users u ON u.id = a.manicure_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        return;
    }

    $message = appointment_notification_message($appointment);
    $emailSent = send_manicure_email($appointment, $message);
    $whatsappSent = send_manicure_whatsapp($appointment, $message);

    if (!$emailSent || !$whatsappSent) {
        error_log(sprintf(
            'Notificação do agendamento %d: email=%s whatsapp=%s',
            $appointmentId,
            $emailSent ? 'ok' : 'nao_enviado',
            $whatsappSent ? 'ok' : 'nao_enviado'
        ));
    }
}
