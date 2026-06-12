<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notifications.php';

try {
    ensure_schema();
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<h1>Erro ao conectar no MySQL</h1>';
    echo '<p>Confira DATABASE_URL ou as variaveis AIVEN_DB_HOST/DB_HOST, AIVEN_DB_PORT/DB_PORT, AIVEN_DB_NAME/DB_NAME, AIVEN_DB_USER/DB_USER e AIVEN_DB_PASS/DB_PASS. Se a Aiven exigir SSL, configure AIVEN_CA_CERT.</p>';
    echo '<pre>' . e($exception->getMessage()) . '</pre>';
    exit;
}

$pdo = db();
$config = app_config();
$page = $_GET['page'] ?? 'inicio';

function uploaded_service_image(): ?string
{
    if (
        empty($_FILES['service_image'])
        || !isset($_FILES['service_image']['error'])
        || $_FILES['service_image']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES['service_image']['error'] !== UPLOAD_ERR_OK) {
        redirect_with('servicos', 'NÃ£o foi possÃ­vel enviar a foto. Tente novamente.', 'error');
    }

    $tmpName = $_FILES['service_image']['tmp_name'];
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $mimeType = mime_content_type($tmpName) ?: '';

    if (!isset($allowedTypes[$mimeType])) {
        redirect_with('servicos', 'Envie uma foto nos formatos JPG, PNG, WEBP ou GIF.', 'error');
    }

    $uploadDir = upload_dir();
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            redirect_with('servicos', 'Erro: A pasta de fotos não tem permissão de escrita.', 'error');
        }
    }

    $filename = 'servico-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        redirect_with('servicos', 'NÃ£o foi possÃ­vel salvar a foto enviada.', 'error');
    }

    return upload_url($filename);
}

function uploaded_about_image(): ?string
{
    if (
        empty($_FILES['about_image'])
        || !isset($_FILES['about_image']['error'])
        || $_FILES['about_image']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES['about_image']['error'] !== UPLOAD_ERR_OK) {
        redirect_with('sobre', 'Nao foi possivel enviar a foto. Tente novamente.', 'error');
    }

    $tmpName = $_FILES['about_image']['tmp_name'];
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $mimeType = mime_content_type($tmpName) ?: '';

    if (!isset($allowedTypes[$mimeType])) {
        redirect_with('sobre', 'Envie uma foto nos formatos JPG, PNG, WEBP ou GIF.', 'error');
    }

    $uploadDir = upload_dir();
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            redirect_with('sobre', 'Erro: A pasta de fotos nao tem permissao de escrita.', 'error');
        }
    }

    $filename = 'sobre-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        redirect_with('sobre', 'Nao foi possivel salvar a foto enviada.', 'error');
    }

    return upload_url($filename);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ];
            redirect_with('agenda', 'Login realizado com sucesso.');
        }

        redirect_with('login', 'E-mail ou senha inválidos.', 'error');
    }

    if ($action === 'book_appointment') {
        $clientName = trim($_POST['client_name'] ?? '');
        $clientPhone = trim($_POST['client_phone'] ?? '');
        $clientEmail = trim($_POST['client_email'] ?? '');
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $manicureId = (int)($_POST['manicure_id'] ?? 0);
        $date = trim($_POST['appointment_date'] ?? '');
        $time = trim($_POST['appointment_time'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($clientName === '' || $clientPhone === '' || !$serviceId || !$manicureId || $date === '' || $time === '') {
            redirect_with('agendar', 'Preencha os campos obrigatórios para marcar o horário.', 'error');
        }

        if ($date < date('Y-m-d')) {
            redirect_with('agendar', 'Escolha uma data de hoje em diante.', 'error');
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE manicure_id = ? AND appointment_date = ? AND appointment_time = ? AND status <> 'cancelado'
        ");
        $stmt->execute([$manicureId, $date, $time . ':00']);

        if ((int)$stmt->fetchColumn() > 0) {
            redirect_with('agendar', 'Esse horário já foi ocupado. Escolha outro horário ou manicure.', 'error');
        }

        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM manicure_availability
            WHERE manicure_id = ? AND available_date = ? AND available_time = ?
        ');
        $stmt->execute([$manicureId, $date, $time . ':00']);

        if ((int)$stmt->fetchColumn() === 0) {
            redirect_with('agendar', 'Esse horário não está disponível para essa manicure.', 'error');
        }

        $stmt = $pdo->prepare("
            INSERT INTO appointments
                (client_name, client_phone, client_email, service_id, manicure_id, appointment_date, appointment_time, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$clientName, $clientPhone, $clientEmail ?: null, $serviceId, $manicureId, $date, $time . ':00', $notes ?: null]);
        notify_manicure_new_appointment($pdo, (int)$pdo->lastInsertId());

        redirect_with('inicio', 'Horário marcado com sucesso. O salão vai confirmar seu atendimento.');
    }

    if ($action === 'create_user') {
        require_admin();
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $allowedRoles = ['admin', 'manicure', 'manicure_admin'];
        $role = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'manicure';

        if ($name === '' || $email === '' || strlen($password) < 6) {
            redirect_with('usuarios', 'Informe nome, e-mail e senha com no mínimo 6 caracteres.', 'error');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $role]);
            redirect_with('usuarios', 'Usuário cadastrado.');
        } catch (PDOException) {
            redirect_with('usuarios', 'Já existe usuário com esse e-mail.', 'error');
        }
    }

    if ($action === 'update_user') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $allowedRoles = ['admin', 'manicure', 'manicure_admin'];
        $role = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'manicure';

        if (!$id || $name === '' || $email === '') {
            redirect_with('usuarios', 'Informe nome e e-mail para atualizar o usuario.', 'error');
        }

        if ($password !== '' && strlen($password) < 6) {
            redirect_with('usuarios', 'A nova senha precisa ter no minimo 6 caracteres.', 'error');
        }

        try {
            if ($password !== '') {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$name, $email, $phone ?: null, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, role = ? WHERE id = ?');
                $stmt->execute([$name, $email, $phone ?: null, $role, $id]);
            }

            if ($id === (int)current_user()['id']) {
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['role'] = $role;
            }

            redirect_with('usuarios', 'Usuario atualizado.');
        } catch (PDOException) {
            redirect_with('usuarios', 'Nao foi possivel atualizar: ja existe usuario com esse e-mail.', 'error');
        }
    }

    if ($action === 'delete_user') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)current_user()['id']) {
            redirect_with('usuarios', 'Você não pode apagar seu próprio usuário logado.', 'error');
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            redirect_with('usuarios', 'Usuário apagado.');
        } catch (PDOException) {
            redirect_with('usuarios', 'Não foi possível apagar: esse usuário já tem agendamentos.', 'error');
        }
    }

    if ($action === 'save_socials') {
        require_admin();
        $configPath = app_config_path();
        $xml = simplexml_load_file($configPath);

        if (!$xml instanceof SimpleXMLElement) {
            redirect_with('redes', 'Não foi possível atualizar as redes sociais.', 'error');
        }

        if (!isset($xml->social)) {
            $xml->addChild('social');
        }

        foreach (['instagram', 'facebook', 'tiktok'] as $field) {
            if (!isset($xml->social->{$field})) {
                $xml->social->addChild($field);
            }
            $xml->social->{$field} = trim($_POST[$field] ?? '');
        }

        if (!isset($xml->salon)) {
            $xml->addChild('salon');
        }
        $xml->salon->show_duration = isset($_POST['show_duration']) ? '1' : '0';

        $xml->asXML($configPath);
        redirect_with('redes', 'Configurações atualizadas.');
    }

    if ($action === 'save_about') {
        require_admin();
        $configPath = app_config_path();
        $xml = simplexml_load_file($configPath);

        if (!$xml instanceof SimpleXMLElement) {
            redirect_with('sobre', 'Nao foi possivel atualizar o Sobre mim.', 'error');
        }

        if (!isset($xml->about)) {
            $xml->addChild('about');
        }

        foreach (['image', 'image_zoom', 'image_pos_x', 'image_pos_y', 'label', 'text', 'signature', 'text_font', 'signature_font', 'label_color', 'text_color', 'signature_color'] as $field) {
            if (!isset($xml->about->{$field})) {
                $xml->about->addChild($field);
            }
        }

        $uploadedImage = uploaded_about_image();
        $imageUrl = $uploadedImage ?? trim($_POST['image_url'] ?? '');
        $allowedFonts = ['brand-serif', 'brand-script', 'font-sans'];
        $textFont = in_array($_POST['text_font'] ?? '', $allowedFonts, true) ? $_POST['text_font'] : 'brand-serif';
        $signatureFont = in_array($_POST['signature_font'] ?? '', $allowedFonts, true) ? $_POST['signature_font'] : 'brand-script';
        $colorPattern = '/^#[0-9a-fA-F]{6}$/';
        $labelColor = preg_match($colorPattern, $_POST['label_color'] ?? '') ? $_POST['label_color'] : '#bd665d';
        $textColor = preg_match($colorPattern, $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#743632';
        $signatureColor = preg_match($colorPattern, $_POST['signature_color'] ?? '') ? $_POST['signature_color'] : '#a65a54';
        $imageZoom = max(0.5, min(4, (float)($_POST['image_zoom'] ?? 1)));
        $imagePosX = max(-45, min(45, (int)($_POST['image_pos_x'] ?? 0)));
        $imagePosY = max(-45, min(45, (int)($_POST['image_pos_y'] ?? 0)));

        $xml->about->image = $imageUrl !== '' ? $imageUrl : 'assets/01.jpeg';
        $xml->about->image_zoom = (string)$imageZoom;
        $xml->about->image_pos_x = (string)$imagePosX;
        $xml->about->image_pos_y = (string)$imagePosY;
        $xml->about->label = trim($_POST['label'] ?? '') ?: 'Sobre mim';
        $xml->about->text = trim($_POST['text'] ?? '') ?: 'Ola! Sou Samara Eduarda, Nail Designer especializada em manicure, pedicure e cuidados que valorizam a beleza das suas unhas.';
        $xml->about->signature = trim($_POST['signature'] ?? '') ?: 'Samara Eduarda';
        $xml->about->text_font = $textFont;
        $xml->about->signature_font = $signatureFont;
        $xml->about->label_color = $labelColor;
        $xml->about->text_color = $textColor;
        $xml->about->signature_color = $signatureColor;

        $xml->asXML($configPath);
        redirect_with('sobre', 'Sobre mim atualizado.');
    }

    if ($action === 'save_marketing_post') {
        require_admin();
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $channel = in_array($_POST['channel'] ?? '', ['instagram', 'whatsapp_status', 'both'], true)
            ? $_POST['channel']
            : 'both';
        $caption = trim($_POST['caption'] ?? '');
        $scheduledFor = trim($_POST['scheduled_for'] ?? '');
        $status = $scheduledFor !== '' ? 'agendado' : 'rascunho';

        if (!$serviceId || $caption === '') {
            redirect_with('marketing', 'Escolha um serviço e escreva uma legenda.', 'error');
        }

        $stmt = $pdo->prepare('SELECT image_url FROM services WHERE id = ? LIMIT 1');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();

        if (!$service) {
            redirect_with('marketing', 'Serviço não encontrado.', 'error');
        }

        $stmt = $pdo->prepare('
            INSERT INTO marketing_posts (service_id, channel, caption, image_url, scheduled_for, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $serviceId,
            $channel,
            $caption,
            $service['image_url'] ?: null,
            $scheduledFor !== '' ? str_replace('T', ' ', $scheduledFor) . ':00' : null,
            $status,
        ]);

        redirect_with('marketing', 'Post de marketing salvo.');
    }

    if ($action === 'publish_marketing_post') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE marketing_posts SET status = 'publicado' WHERE id = ?");
        $stmt->execute([$id]);
        redirect_with('marketing', 'Post marcado como publicado.');
    }

    if ($action === 'delete_marketing_post') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM marketing_posts WHERE id = ?');
        $stmt->execute([$id]);
        redirect_with('marketing', 'Post apagado.');
    }

    if ($action === 'save_service') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $rawPrice = trim((string)($_POST['price'] ?? '0'));
        $normalizedPrice = str_contains($rawPrice, ',')
            ? str_replace(',', '.', str_replace('.', '', $rawPrice))
            : $rawPrice;
        $rawPriceDecorated = trim((string)($_POST['price_decorated'] ?? '0'));
        $normalizedPriceDecorated = str_contains($rawPriceDecorated, ',')
            ? str_replace(',', '.', str_replace('.', '', $rawPriceDecorated))
            : $rawPriceDecorated;
        $uploadedImage = uploaded_service_image();
        $imageFit = in_array($_POST['image_fit'] ?? '', ['contain', 'cover'], true)
            ? $_POST['image_fit']
            : 'contain';
        $imageZoom = max(0.5, min(4, (float)($_POST['image_zoom'] ?? 1)));
        $imagePosX = max(-100, min(100, (int)($_POST['image_pos_x'] ?? 0)));
        $imagePosY = max(-100, min(100, (int)($_POST['image_pos_y'] ?? 0)));
        $imageRotation = ((int)($_POST['image_rotation'] ?? 0) % 360 + 360) % 360;

        $data = [
            trim($_POST['name'] ?? ''),
            trim($_POST['description'] ?? ''),
            (float)$normalizedPrice,
            (float)$normalizedPriceDecorated,
            max(15, (int)($_POST['duration_minutes'] ?? 30)),
            $uploadedImage ?? trim($_POST['image_url'] ?? ''),
            $imageFit,
            $imageZoom,
            $imagePosX,
            $imagePosY,
            $imageRotation,
            isset($_POST['active']) ? 1 : 0,
        ];

        if ($data[0] === '') {
            redirect_with('servicos', 'O nome do serviço é obrigatório.', 'error');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare('
                UPDATE services
                SET name = ?, description = ?, price = ?, price_decorated = ?, duration_minutes = ?, image_url = ?, image_fit = ?, image_zoom = ?, image_pos_x = ?, image_pos_y = ?, image_rotation = ?, active = ?
                WHERE id = ?
            ');
            $stmt->execute([...$data, $id]);
            redirect_with('servicos', 'Serviço atualizado.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO services (name, description, price, price_decorated, duration_minutes, image_url, image_fit, image_zoom, image_pos_x, image_pos_y, image_rotation, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute($data);
        redirect_with('servicos', 'Serviço cadastrado.');
    }

    if ($action === 'save_availability') {
        require_login();
        $manicureId = is_admin() ? (int)($_POST['manicure_id'] ?? 0) : (int)current_user()['id'];
        $startDate = trim($_POST['available_start_date'] ?? '');
        $endDate = trim($_POST['available_end_date'] ?? '');
        $times = $_POST['available_times'] ?? [];

        if (!$manicureId || $startDate === '' || !is_array($times) || $times === []) {
            redirect_with('agenda', 'Escolha as datas e pelo menos um horário para disponibilizar.', 'error');
        }

        if ($endDate === '') {
            $endDate = $startDate;
        }

        if ($startDate < date('Y-m-d') || $endDate < date('Y-m-d')) {
            redirect_with('agenda', 'Disponibilize apenas datas de hoje em diante.', 'error');
        }

        if ($endDate < $startDate) {
            redirect_with('agenda', 'A data final precisa ser igual ou depois da data inicial.', 'error');
        }

        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end = DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            redirect_with('agenda', 'Informe datas válidas.', 'error');
        }

        $validTimes = schedule_times();
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO manicure_availability (manicure_id, available_date, available_time)
            VALUES (?, ?, ?)
        ');

        while ($start <= $end) {
            $date = $start->format('Y-m-d');
            foreach ($times as $time) {
                if (in_array($time, $validTimes, true)) {
                    $stmt->execute([$manicureId, $date, $time . ':00']);
                }
            }
            $start->modify('+1 day');
        }

        redirect_with('agenda', 'Horários disponibilizados.');
    }

    if ($action === 'delete_availability') {
        require_login();
        $id = (int)($_POST['id'] ?? 0);
        $sql = 'DELETE FROM manicure_availability WHERE id = ?';
        $params = [$id];

        if (!is_admin()) {
            $sql .= ' AND manicure_id = ?';
            $params[] = current_user()['id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        redirect_with('agenda', 'Horário removido da disponibilidade.');
    }

    if ($action === 'delete_service') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
        try {
            $stmt->execute([$id]);
            redirect_with('servicos', 'Serviço apagado.');
        } catch (PDOException) {
            redirect_with('servicos', 'Não foi possível apagar: já existem agendamentos desse serviço.', 'error');
        }
    }

    if ($action === 'update_appointment') {
        require_login();
        $id = (int)($_POST['id'] ?? 0);
        $allowed = ['marcado', 'confirmado', 'concluido', 'cancelado'];
        $status = in_array($_POST['status'] ?? '', $allowed, true) ? $_POST['status'] : 'marcado';

        $sql = 'UPDATE appointments SET status = ? WHERE id = ?';
        $params = [$status, $id];

        if (!is_admin()) {
            $sql .= ' AND manicure_id = ?';
            $params[] = current_user()['id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        redirect_with('agenda', 'Agendamento atualizado.');
    }
}

if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=inicio');
    exit;
}

$loginPages = ['agenda'];
$adminPages = ['marketing', 'sobre', 'usuarios', 'servicos'];

if (in_array($page, $adminPages, true)) {
    require_admin();
} elseif (in_array($page, $loginPages, true)) {
    require_login();
}

$services = $pdo->query('SELECT * FROM services WHERE active = 1 ORDER BY name')->fetchAll();
$manicures = $pdo->query("SELECT id, name FROM users WHERE role IN ('manicure', 'manicure_admin') ORDER BY name")->fetchAll();
$stmt = $pdo->query("
    SELECT ma.manicure_id, ma.available_date, TIME_FORMAT(ma.available_time, '%H:%i') AS available_time
    FROM manicure_availability ma
    LEFT JOIN appointments a
        ON a.manicure_id = ma.manicure_id
        AND a.appointment_date = ma.available_date
        AND a.appointment_time = ma.available_time
        AND a.status <> 'cancelado'
    WHERE ma.available_date >= CURDATE()
        AND a.id IS NULL
    ORDER BY ma.available_date, ma.available_time
");
$availabilityByManicure = [];
foreach ($stmt->fetchAll() as $slot) {
    $manicureId = (string)$slot['manicure_id'];
    $date = $slot['available_date'];
    $availabilityByManicure[$manicureId][$date][] = $slot['available_time'];
}
$flash = flash();
$about = $config->about ?? null;
$aboutImage = trim((string)($about->image ?? '')) ?: 'assets/01.jpeg';
$aboutLabel = trim((string)($about->label ?? '')) ?: 'Sobre mim';
$aboutText = trim((string)($about->text ?? '')) ?: 'Ola! Sou Samara Eduarda, Nail Designer especializada em manicure, pedicure e cuidados que valorizam a beleza das suas unhas.';
$aboutSignature = trim((string)($about->signature ?? '')) ?: 'Samara Eduarda';
$aboutTextFont = trim((string)($about->text_font ?? '')) ?: 'brand-serif';
$aboutSignatureFont = trim((string)($about->signature_font ?? '')) ?: 'brand-script';
$aboutLabelColor = trim((string)($about->label_color ?? '')) ?: '#bd665d';
$aboutTextColor = trim((string)($about->text_color ?? '')) ?: '#743632';
$aboutSignatureColor = trim((string)($about->signature_color ?? '')) ?: '#a65a54';
$aboutImageZoom = max(0.5, min(4, (float)($about->image_zoom ?? 1)));
$aboutImagePosX = max(-45, min(45, (int)($about->image_pos_x ?? 0)));
$aboutImagePosY = max(-45, min(45, (int)($about->image_pos_y ?? 0)));
$aboutImageTransform = sprintf(
    'translate(%d%%, %d%%) scale(%s)',
    $aboutImagePosX,
    $aboutImagePosY,
    rtrim(rtrim(number_format($aboutImageZoom, 2, '.', ''), '0'), '.')
);

function nav_link(string $target, string $label, string $current): string
{
    $active = $target === $current ? 'bg-pink-600 text-white shadow-sm' : 'text-stone-950 hover:bg-rose-50 hover:text-pink-700';
    return '<a class="rounded-md px-5 py-3 text-sm font-semibold transition ' . $active . '" href="?page=' . e($target) . '">' . e($label) . '</a>';
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'manicure_admin' => 'Manicure admin',
        default => 'Manicure',
    };
}

function service_image_adjuster(string $id, array $service = []): string
{
    $imageUrl = (string)($service['image_url'] ?? '');
    $zoom = max(0.5, min(4, (float)($service['image_zoom'] ?? 1)));
    $posX = max(-100, min(100, (int)($service['image_pos_x'] ?? 0)));
    $posY = max(-100, min(100, (int)($service['image_pos_y'] ?? 0)));
    $rotation = ((int)($service['image_rotation'] ?? 0) % 360 + 360) % 360;
    $src = $imageUrl !== '' ? $imageUrl : 'assets/catalago.jpeg';

    return '
        <section class="service-image-adjuster rounded-md border border-pink-100 bg-pink-50/50 p-3 lg:col-span-6" data-adjuster="' . e($id) . '">
            <input type="hidden" name="image_fit" value="contain">
            <input type="hidden" name="image_zoom" value="' . e((string)$zoom) . '" data-adjuster-zoom-input>
            <input type="hidden" name="image_pos_x" value="' . e((string)$posX) . '" data-adjuster-x-input>
            <input type="hidden" name="image_pos_y" value="' . e((string)$posY) . '" data-adjuster-y-input>
            <input type="hidden" name="image_rotation" value="' . e((string)$rotation) . '" data-adjuster-rotation-input>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div class="w-full max-w-sm">
                    <span class="mb-2 block text-sm font-black text-slate-800">Ajuste a foto como ela vai aparecer no catalogo</span>
                    <div class="service-image-frame relative h-40 w-full cursor-grab touch-none overflow-hidden rounded-lg border border-pink-200 bg-rose-50">
                        <img data-adjuster-image class="absolute inset-0 h-full w-full object-contain" src="' . e($src) . '" alt="">
                    </div>
                    <button type="button" data-adjuster-ok class="mt-2 w-full rounded-md bg-pink-600 px-4 py-2 text-sm font-black text-white hover:bg-pink-700">OK, usar este ajuste</button>
                </div>
                <div class="grid flex-1 gap-3 lg:pt-7">
                    <label class="block">
                        <span class="mb-1 block text-xs font-bold text-slate-700">Zoom</span>
                        <input type="range" min="0.5" max="4" step="0.05" value="' . e((string)$zoom) . '" data-adjuster-zoom class="w-full accent-pink-600">
                    </label>
                    <div>
                        <span class="mb-1 block text-xs font-bold text-slate-700">Virar foto</span>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" data-adjuster-rotate-left class="rounded-md border border-pink-200 bg-white px-3 py-2 text-sm font-black text-pink-700 hover:bg-pink-50">Esquerda</button>
                            <button type="button" data-adjuster-rotate-right class="rounded-md border border-pink-200 bg-white px-3 py-2 text-sm font-black text-pink-700 hover:bg-pink-50">Direita</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    ';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string)$config->salon->name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Allura&family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --rose-ink: #8f3f39;
            --rose-soft: #f8d8d9;
            --rose-main: #de2a7a;
            --paper: #fffaf8;
        }

        body {
            font-family: Inter, system-ui, sans-serif;
        }

        .brand-serif {
            font-family: "Cormorant Garamond", Georgia, serif;
        }

        .brand-script {
            font-family: Allura, cursive;
        }

        .home-hero {
            background:
                linear-gradient(90deg, rgba(255, 246, 245, .98) 0%, rgba(255, 246, 245, .9) 34%, rgba(255, 246, 245, .35) 58%, rgba(235, 134, 134, .2) 100%),
                url("https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=1800&q=85");
            background-position: center;
            background-size: cover;
        }

        .service-image-frame img,
        #catalogo article img {
            transform-origin: center;
        }

        @media (max-width: 640px) {
            html,
            body {
                max-width: 100%;
                overflow-x: hidden;
            }

            header > div {
                align-items: flex-start !important;
                gap: .75rem !important;
                padding: .75rem 1rem !important;
            }

            header a[href="?page=inicio"] {
                width: 100%;
            }

            header a[href="?page=inicio"] img {
                height: 54px !important;
                width: 54px !important;
                border-radius: .75rem !important;
            }

            header a[href="?page=inicio"] strong {
                max-width: 260px;
                font-size: 1.05rem !important;
                line-height: 1.08 !important;
            }

            header nav {
                display: grid !important;
                width: 100%;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .5rem !important;
            }

            header nav a {
                display: flex !important;
                min-height: 44px;
                align-items: center;
                justify-content: center;
                padding: .7rem .55rem !important;
                text-align: center;
                font-size: .9rem !important;
                line-height: 1.05 !important;
            }

            main.mx-auto {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            .home-hero {
                background:
                    linear-gradient(180deg, rgba(255, 246, 245, .98) 0%, rgba(255, 246, 245, .88) 48%, rgba(255, 246, 245, .68) 100%),
                    url("https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=900&q=80");
                background-position: center top;
            }

            .home-hero > div {
                min-height: 0 !important;
                gap: 1.5rem !important;
                padding: 2rem 1.25rem 2.25rem !important;
            }

            .home-hero .brand-serif {
                font-size: clamp(3rem, 15vw, 4.2rem) !important;
                line-height: .82 !important;
                letter-spacing: 0 !important;
            }

            .home-hero .brand-script {
                margin-top: .85rem !important;
                font-size: clamp(2.35rem, 12vw, 3.4rem) !important;
            }

            .home-hero .brand-script + div {
                margin-top: 1.5rem !important;
                gap: 1rem !important;
                font-size: 1.12rem !important;
                line-height: 1.55 !important;
            }

            .home-hero .brand-script + div p {
                align-items: flex-start !important;
            }

            .home-hero .brand-script + div p > span:first-child {
                height: 2.5rem !important;
                width: 2.5rem !important;
            }

            .home-hero .brand-script + div p > span:last-child {
                width: auto !important;
                min-width: 0;
            }

            .home-hero .mt-7 {
                display: grid !important;
                gap: .8rem !important;
                margin-top: 1.5rem !important;
            }

            .home-hero .mt-7 a {
                width: 100%;
                padding: 1rem 1.25rem !important;
                text-align: center;
                font-size: 1rem !important;
            }

            .home-hero aside {
                align-self: stretch !important;
                border-radius: .9rem !important;
                padding: .45rem !important;
            }

            .home-hero aside > div:first-child {
                height: 270px !important;
                border-radius: .75rem !important;
            }

            .home-hero aside > div:last-child {
                padding: 1.15rem 1rem 1.35rem !important;
            }

            .home-hero aside .brand-script {
                font-size: 2.35rem !important;
            }

            #catalogo {
                padding: 2rem 1rem !important;
            }

            #catalogo .mb-4 {
                align-items: center !important;
            }

            #catalogo h2 {
                font-size: 1.8rem !important;
                line-height: 1 !important;
            }

            #catalogo article h3 {
                font-size: 1.55rem !important;
                line-height: 1.05 !important;
            }

            section {
                min-width: 0;
            }

            input,
            select,
            textarea,
            button {
                max-width: 100%;
            }

            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
            }

            table {
                min-width: 720px;
            }

            #marketing_canvas {
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-[#fff7f5] text-stone-900">
    <header class="sticky top-0 z-20 border-b border-rose-100 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-3 px-6 py-3">
            <a href="?page=inicio" class="flex items-center gap-3">
                <img class="h-[66px] w-[66px] rounded-xl border border-rose-100 bg-white object-contain p-1 shadow-sm" src="assets/logo%20samara.png" alt="<?= e((string)$config->salon->name) ?>">
                <span>
                    <strong class="block text-lg leading-tight"><?= e((string)$config->salon->name) ?></strong>
                    <small class="text-slate-500"><?= e((string)$config->salon->subtitle) ?></small>
                </span>
            </a>
            <nav class="flex flex-wrap items-center gap-1">
                <?= nav_link('inicio', 'Catálogo', $page) ?>
                <?= nav_link('agendar', 'Marcar horário', $page) ?>
                <?= nav_link('redes', 'Redes Sociais', $page) ?>
                <?php if (is_logged_in()): ?>
                    <?= nav_link('agenda', 'Agenda', $page) ?>
                    <?= nav_link('servicos', 'Serviços', $page) ?>
                    <?= nav_link('usuarios', 'Usuários', $page) ?>
                    <?php if (is_admin()): ?>
                        <?= nav_link('marketing', 'Marketing', $page) ?>
                        <?= nav_link('sobre', 'Sobre mim', $page) ?>
                    <?php endif; ?>
                    <a class="rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100" href="?page=logout">Sair</a>
                <?php else: ?>
                    <?= nav_link('login', 'Login equipe', $page) ?>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="<?= $page === 'inicio' ? '' : 'mx-auto max-w-7xl px-4 py-8' ?>">
        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border px-4 py-3 text-sm font-medium <?= $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($page === 'inicio'): ?>
            <section class="home-hero border-b border-rose-100">
                <div class="mx-auto grid min-h-[660px] max-w-[1440px] gap-10 px-6 py-10 lg:grid-cols-[1fr_330px] lg:px-24 lg:py-16">
                    <div class="flex max-w-4xl flex-col justify-center">
                        <p class="brand-serif text-[clamp(58px,8vw,112px)] font-bold uppercase leading-[.78] text-[#bd665d]">
                            Samara<br>Eduarda
                        </p>
                        <p class="brand-script mt-5 text-[clamp(46px,5vw,70px)] leading-none text-[#b76862]">Nail Designer</p>

                        <div class="mt-8 grid max-w-2xl gap-4 text-xl leading-relaxed text-[#7b3935]">
                            <p class="flex gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-rose-200 bg-white/60 text-pink-500">+</span>
                                <span>Realce sua beleza com unhas impecáveis e atendimento personalizado.</span>
                            </p>
                            <p class="flex gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-rose-200 bg-white/60 text-pink-500">~</span>
                                <span>Especialista em unhas que valorizam sua autoestima.</span>
                            </p>
                        </div>

                        <div class="mt-7 flex flex-wrap gap-4">
                            <a href="?page=agendar" class="rounded-lg bg-pink-600 px-9 py-4 text-lg font-black text-white shadow-sm transition hover:bg-pink-700">Agendar Agora</a>
                            <a href="#catalogo" class="rounded-lg border border-pink-300 bg-white/70 px-9 py-4 text-lg font-black text-pink-600 shadow-sm transition hover:bg-white">Ver Trabalhos</a>
                        </div>

                    </div>

                    <aside class="self-center rounded-2xl border border-rose-200 bg-white/90 p-2 shadow-sm">
                        <div class="relative h-[370px] w-full overflow-hidden rounded-xl bg-rose-50">
                            <img class="pointer-events-none absolute inset-0 h-full w-full select-none object-cover object-center" draggable="false" style="transform: <?= e($aboutImageTransform) ?>;" src="<?= e($aboutImage) ?>" alt="<?= e($aboutSignature) ?>">
                        </div>
                        <div class="px-6 py-7 text-center" style="color: <?= e($aboutTextColor) ?>">
                            <p class="text-xs font-bold uppercase tracking-wide" style="color: <?= e($aboutLabelColor) ?>"><?= e($aboutLabel) ?></p>
                            <p class="<?= e($aboutTextFont) ?> mt-4 text-lg leading-relaxed"><?= e($aboutText) ?></p>
                            <p class="<?= e($aboutSignatureFont) ?> mt-6 text-3xl" style="color: <?= e($aboutSignatureColor) ?>"><?= e($aboutSignature) ?></p>
                        </div>
                    </aside>
                </div>
            </section>

            <section id="catalogo" class="mx-auto max-w-[1440px] px-6 py-10 lg:px-24">
                <div class="mb-4 flex items-end gap-4">
                    <h2 class="brand-serif text-3xl font-bold uppercase tracking-wide text-stone-950">Nossos Serviços</h2>
                    <span class="mb-3 h-px flex-1 bg-rose-200"></span>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <?php foreach ($services as $service): ?>
                        <?php
                        $imageZoom = max(0.5, min(4, (float)($service['image_zoom'] ?? 1)));
                        $imagePosX = max(-100, min(100, (int)($service['image_pos_x'] ?? 0)));
                        $imagePosY = max(-100, min(100, (int)($service['image_pos_y'] ?? 0)));
                        $imageRotation = ((int)($service['image_rotation'] ?? 0) % 360 + 360) % 360;
                        $imageTransform = sprintf(
                            'translate(%d%%, %d%%) rotate(%ddeg) scale(%s)',
                            $imagePosX,
                            $imagePosY,
                            $imageRotation,
                            rtrim(rtrim(number_format($imageZoom, 2, '.', ''), '0'), '.')
                        );
                        ?>
                        <article class="overflow-hidden rounded-lg border border-rose-100 bg-white text-center shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                            <div class="relative h-40 w-full overflow-hidden bg-rose-50">
                                <img class="absolute inset-0 h-full w-full object-contain" style="transform: <?= e($imageTransform) ?>;" src="<?= e($service['image_url'] ?: 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=700&q=85') ?>" alt="<?= e($service['name']) ?>">
                            </div>
                            <div class="p-5">
                                <h3 class="brand-serif text-2xl font-bold text-stone-950"><?= e($service['name']) ?></h3>
                                <p class="mt-1 min-h-10 text-sm leading-relaxed text-stone-700"><?= e($service['description']) ?></p>
                                <div class="mt-4 flex flex-col items-center gap-2 text-sm">
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <span class="rounded-md bg-pink-50 px-3 py-1 text-xs font-bold text-pink-700">Sem decoração: <?= money_br($service['price']) ?></span>
                                        <span class="rounded-md bg-pink-100 px-3 py-1 text-xs font-bold text-pink-800">Com decoração: <?= money_br($service['price_decorated'] ?? 0) ?></span>
                                    </div>
                                    <?php if ((string)$config->salon->show_duration === '1'): ?>
                                        <span class="rounded-md bg-rose-50 px-3 py-1 font-bold text-[#7b3935]"><?= (int)$service['duration_minutes'] ?> min</span>
                                    <?php endif; ?>
                                </div>
                                <a class="mt-4 inline-flex rounded-md border border-pink-200 px-4 py-2 text-sm font-black text-pink-700 transition hover:bg-pink-50" href="?page=agendar&service=<?= (int)$service['id'] ?>">Agendar</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="mx-auto max-w-[1440px] px-6 pb-8 lg:px-24">
                <div class="grid overflow-hidden rounded-md border border-rose-100 bg-white/85 shadow-[0_8px_18px_rgba(122,57,53,.10)] backdrop-blur sm:grid-cols-3">
                    <div class="flex min-h-24 flex-col items-center justify-center border-b border-rose-100 px-4 py-4 text-center sm:border-b-0 sm:border-r lg:px-5">
                        <strong class="brand-serif flex flex-wrap items-baseline justify-center gap-1.5 text-lg font-bold leading-none text-stone-950 lg:text-xl">
                            <span class="tracking-[1px]" aria-label="5 estrelas">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                            <span>5.0</span>
                        </strong>
                        <span class="mt-1 text-sm leading-tight text-[#7b3935]">Nossas clientes recomendam</span>
                    </div>
                    <div class="flex min-h-24 flex-col items-center justify-center border-b border-rose-100 px-4 py-4 text-center sm:border-b-0 sm:border-r lg:px-5">
                        <strong class="brand-serif block text-xl font-bold leading-none text-stone-950 lg:text-2xl">+300</strong>
                        <span class="mt-1 text-sm leading-tight text-[#7b3935]">atendimentos realizados</span>
                    </div>
                    <div class="flex min-h-24 flex-col items-center justify-center px-4 py-4 text-center lg:px-5">
                        <strong class="brand-serif block text-xl font-bold leading-none text-stone-950 lg:text-2xl">+200</strong>
                        <span class="mt-1 text-sm leading-tight text-[#7b3935]">clientes satisfeitas</span>
                    </div>
                </div>
            </section>
        <?php elseif ($page === 'agendar'): ?>
            <section class="grid gap-6 lg:grid-cols-[.8fr_1.2fr]">
                <div>
                    <h1 class="text-3xl font-black text-slate-950">Marcar horário</h1>
                    <p class="mt-2 text-slate-600">Informe seus dados e escolha serviço, manicure, data e horário.</p>
                    <div class="mt-5 rounded-lg border border-rose-100 bg-white p-5 text-sm text-slate-600 shadow-sm">
                        <p><strong>Horário:</strong> <?= e((string)$config->schedule->start) ?> até <?= e((string)$config->schedule->end) ?></p>
                        <p><strong>Telefone:</strong> <a class="font-bold text-pink-700 hover:underline" href="<?= e(whatsapp_link((string)$config->salon->phone)) ?>" target="_blank" rel="noopener"><?= e((string)$config->salon->phone) ?></a></p>
                        <p><strong>Endereço:</strong> <?= e((string)$config->salon->address) ?></p>
                    </div>
                </div>
                <form method="post" class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="book_appointment">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Nome completo *</span>
                            <input required name="client_name" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">WhatsApp *</span>
                            <input required name="client_phone" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Serviço *</span>
                            <select required name="service_id" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                                <option value="">Selecione</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= (int)$service['id'] ?>" <?= selected((string)($_GET['service'] ?? ''), (string)$service['id']) ?>>
                                        <?= e($service['name']) ?> - Sem: <?= money_br($service['price']) ?> / Com: <?= money_br($service['price_decorated'] ?? 0) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Manicure *</span>
                            <select required name="manicure_id" id="manicure_id" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                                <option value="">Selecione</option>
                                <?php foreach ($manicures as $manicure): ?>
                                    <option value="<?= (int)$manicure['id'] ?>"><?= e($manicure['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Data *</span>
                            <select required name="appointment_date" id="appointment_date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                                <option value="">Escolha a manicure primeiro</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Horário *</span>
                            <select required name="appointment_time" id="appointment_time" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                                <option value="">Escolha uma data primeiro</option>
                            </select>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-bold text-slate-700">Observações</span>
                            <textarea name="notes" rows="3" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100"></textarea>
                        </label>
                    </div>
                    <button class="mt-5 w-full rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Confirmar agendamento</button>
                </form>
            </section>
        <?php elseif ($page === 'redes'): ?>
            <?php
            $socialLinks = [
                'WhatsApp' => whatsapp_link((string)$config->salon->phone),
                'Instagram' => trim((string)($config->social->instagram ?? '')),
                'Facebook' => trim((string)($config->social->facebook ?? '')),
                'TikTok' => trim((string)($config->social->tiktok ?? '')),
            ];
            ?>
            <section class="grid gap-6 lg:grid-cols-[.8fr_1.2fr]">
                <div>
                    <h1 class="text-3xl font-black text-slate-950">Redes Sociais</h1>
                    <p class="mt-2 text-slate-600">Acompanhe novidades, modelos de unhas e horários pelo nosso contato oficial.</p>
                </div>
                <div class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <?php foreach ($socialLinks as $label => $url): ?>
                            <?php if ($url !== ''): ?>
                                <a class="rounded-lg border border-pink-200 px-5 py-4 text-center font-black text-pink-700 hover:bg-pink-50" href="<?= e($url) ?>" target="_blank" rel="noopener">
                                    <?= e($label) ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if (is_admin()): ?>
                        <form method="post" class="mt-6 grid gap-4 border-t border-slate-100 pt-5">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_socials">
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Instagram</span>
                                <input name="instagram" value="<?= e((string)($config->social->instagram ?? '')) ?>" placeholder="https://instagram.com/seu_perfil" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Facebook</span>
                                <input name="facebook" value="<?= e((string)($config->social->facebook ?? '')) ?>" placeholder="https://facebook.com/sua_pagina" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">TikTok</span>
                                <input name="tiktok" value="<?= e((string)($config->social->tiktok ?? '')) ?>" placeholder="https://tiktok.com/@seu_perfil" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer py-2">
                                <input type="checkbox" name="show_duration" value="1" <?= (string)$config->salon->show_duration === '1' ? 'checked' : '' ?> class="sr-only peer">
                                <div class="relative w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-pink-600"></div>
                                <span class="text-sm font-bold text-slate-700">Exibir tempo de atendimento no catálogo</span>
                            </label>
                            <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Salvar redes sociais</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($page === 'marketing'): ?>
            <?php
            $marketingServices = $pdo->query('SELECT id, name, price, price_decorated, description, image_url FROM services WHERE active = 1 ORDER BY name')->fetchAll();
            $stmt = $pdo->query("
                SELECT mp.*, s.name AS service_name, s.price
                FROM marketing_posts mp
                LEFT JOIN services s ON s.id = mp.service_id
                ORDER BY COALESCE(mp.scheduled_for, mp.created_at) DESC
                LIMIT 60
            ");
            $marketingPosts = $stmt->fetchAll();
            ?>
            <section class="grid gap-6 lg:grid-cols-[.9fr_1.1fr]">
                <div class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <h1 class="text-2xl font-black text-slate-950">Marketing / Redes Sociais</h1>
                    <p class="mt-1 text-sm text-slate-600">Monte uma arte do catálogo, salve o planejamento e baixe a imagem para postar no Status.</p>

                    <form method="post" class="mt-5 grid gap-4">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_marketing_post">
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Serviço do catálogo</span>
                            <select required name="service_id" id="marketing_service" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                <option value="">Selecione</option>
                                <?php foreach ($marketingServices as $service): ?>
                                    <option value="<?= (int)$service['id'] ?>"><?= e($service['name']) ?> - <?= money_br($service['price']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Onde postar</span>
                            <select name="channel" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                <option value="both">Instagram e WhatsApp Status</option>
                                <option value="instagram">Instagram</option>
                                <option value="whatsapp_status">WhatsApp Status</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Agendar para</span>
                            <input type="datetime-local" name="scheduled_for" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Legenda</span>
                            <textarea required name="caption" id="marketing_caption" rows="4" placeholder="Ex: Agenda aberta para essa semana. Chame no WhatsApp e garanta seu horário." class="w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Salvar post</button>
                    </form>
                </div>

                <div class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <h2 class="text-2xl font-black text-slate-950">Arte pronta</h2>
                    <div class="mt-4 grid gap-4 lg:grid-cols-[360px_1fr]">
                        <canvas id="marketing_canvas" width="1080" height="1920" class="aspect-[9/16] w-full max-w-[360px] rounded-lg border border-rose-100 bg-rose-50"></canvas>
                        <div class="space-y-3 text-sm text-slate-600">
                            <p>A prévia usa a foto e o preço do serviço selecionado. Depois de montar, baixe a imagem e publique no Status do WhatsApp ou no Instagram.</p>
                            <button type="button" id="download_marketing_art" class="w-full rounded-lg bg-slate-900 px-5 py-3 font-black text-white hover:bg-slate-800">Baixar imagem PNG</button>
                            <p class="rounded-md bg-amber-50 p-3 text-amber-800">Instagram automático fica pronto para conectar quando o token oficial da Meta estiver configurado.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 class="text-2xl font-black text-slate-950">Posts salvos</h2>
                    <div class="mt-4 grid gap-3">
                        <?php if ($marketingPosts === []): ?>
                            <p class="text-sm text-slate-500">Nenhum post salvo ainda.</p>
                        <?php endif; ?>
                        <?php foreach ($marketingPosts as $post): ?>
                            <div class="grid gap-3 rounded-lg border border-slate-100 p-4 lg:grid-cols-[1fr_auto] lg:items-center">
                                <div>
                                    <strong><?= e($post['service_name'] ?? 'Serviço removido') ?></strong>
                                    <small class="block text-slate-500">
                                        <?= e($post['channel']) ?> · <?= e($post['status']) ?>
                                        <?php if ($post['scheduled_for']): ?>
                                            · <?= date('d/m/Y H:i', strtotime($post['scheduled_for'])) ?>
                                        <?php endif; ?>
                                    </small>
                                    <p class="mt-2 text-sm text-slate-600"><?= e($post['caption']) ?></p>
                                </div>
                                <div class="flex gap-2">
                                    <?php if ($post['status'] !== 'publicado'): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="publish_marketing_post">
                                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                                            <button class="rounded-md bg-pink-600 px-3 py-2 text-sm font-bold text-white">Publicado</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Apagar este post?')">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_marketing_post">
                                        <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                                        <button class="rounded-md border border-red-200 px-3 py-2 text-sm font-bold text-red-700">Apagar</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php elseif ($page === 'login'): ?>
            <section class="mx-auto max-w-md rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                <h1 class="text-2xl font-black text-slate-950">Login da equipe</h1>
                <form method="post" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <label class="block">
                        <span class="text-sm font-bold text-slate-700">Login</span>
                        <input required type="text" name="email" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-bold text-slate-700">Senha</span>
                        <input required type="password" name="password" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-100">
                    </label>
                    <button class="w-full rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Entrar</button>
                </form>
            </section>
        <?php elseif ($page === 'agenda'): ?>
            <?php
            $params = [];
            $where = '';
            if (!is_admin()) {
                $where = 'WHERE a.manicure_id = ?';
                $params[] = current_user()['id'];
            }
            $stmt = $pdo->prepare("
                SELECT a.*, s.name AS service_name, u.name AS manicure_name
                FROM appointments a
                JOIN services s ON s.id = a.service_id
                JOIN users u ON u.id = a.manicure_id
                {$where}
                ORDER BY a.appointment_date DESC, a.appointment_time DESC
                LIMIT 120
            ");
            $stmt->execute($params);
            $appointments = $stmt->fetchAll();

            $availabilitySql = "
                SELECT ma.*, u.name AS manicure_name,
                    COUNT(a.id) AS booked_count,
                    TIME_FORMAT(ma.available_time, '%H:%i') AS formatted_time
                FROM manicure_availability ma
                JOIN users u ON u.id = ma.manicure_id
                LEFT JOIN appointments a
                    ON a.manicure_id = ma.manicure_id
                    AND a.appointment_date = ma.available_date
                    AND a.appointment_time = ma.available_time
                    AND a.status <> 'cancelado'
                WHERE ma.available_date >= CURDATE()
            ";
            $availabilityParams = [];
            if (!is_admin()) {
                $availabilitySql .= ' AND ma.manicure_id = ?';
                $availabilityParams[] = current_user()['id'];
            }
            $availabilitySql .= '
                GROUP BY ma.id, u.name
                ORDER BY ma.available_date, ma.available_time
                LIMIT 120
            ';
            $stmt = $pdo->prepare($availabilitySql);
            $stmt->execute($availabilityParams);
            $availabilityRows = $stmt->fetchAll();
            ?>
            <section>
                <h1 class="text-3xl font-black text-slate-950">Agenda</h1>
                <p class="mt-1 text-slate-600"><?= is_admin() ? 'Todos os horários do salão.' : 'Seus horários marcados.' ?></p>
                <div class="mt-5 rounded-lg border border-rose-100 bg-white p-5 shadow-sm">
                    <h2 class="text-xl font-black text-slate-950">Disponibilizar horários</h2>
                    <form method="post" class="mt-4 grid gap-4 lg:grid-cols-4">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_availability">
                        <?php if (is_admin()): ?>
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Manicure</span>
                                <select required name="manicure_id" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                                    <option value="">Selecione</option>
                                    <?php foreach ($manicures as $manicure): ?>
                                        <option value="<?= (int)$manicure['id'] ?>"><?= e($manicure['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Data inicial</span>
                            <input required type="date" min="<?= date('Y-m-d') ?>" name="available_start_date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Data final</span>
                            <input type="date" min="<?= date('Y-m-d') ?>" name="available_end_date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                            <small class="mt-1 block text-slate-500">Deixe vazio para liberar apenas a data inicial.</small>
                        </label>
                        <div class="lg:col-span-4">
                            <span class="text-sm font-bold text-slate-700">Horários disponíveis</span>
                            <div class="mt-2 grid gap-2 sm:grid-cols-4 lg:grid-cols-6">
                                <?php foreach (schedule_times() as $time): ?>
                                    <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm font-bold">
                                        <input type="checkbox" name="available_times[]" value="<?= e($time) ?>">
                                        <?= e($time) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700 lg:col-span-4">Salvar disponibilidade</button>
                    </form>

                    <div class="mt-5">
                        <h3 class="text-sm font-black uppercase text-slate-500">Próximos horários liberados</h3>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <?php if ($availabilityRows === []): ?>
                                <p class="text-sm text-slate-500">Nenhum horário disponível cadastrado.</p>
                            <?php endif; ?>
                            <?php foreach ($availabilityRows as $slot): ?>
                                <form method="post" class="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_availability">
                                    <input type="hidden" name="id" value="<?= (int)$slot['id'] ?>">
                                    <span>
                                        <strong><?= date('d/m/Y', strtotime($slot['available_date'])) ?> às <?= e($slot['formatted_time']) ?></strong>
                                        <small class="block text-slate-500"><?= e($slot['manicure_name']) ?><?= (int)$slot['booked_count'] > 0 ? ' · agendado' : '' ?></small>
                                    </span>
                                    <button class="font-bold text-red-700 hover:underline">Remover</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="mt-5 overflow-x-auto rounded-lg border border-rose-100 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Data</th>
                                <th class="px-4 py-3">Cliente</th>
                                <th class="px-4 py-3">Serviço</th>
                                <th class="px-4 py-3">Manicure</th>
                                <th class="px-4 py-3">Contato</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($appointments as $appointment): ?>
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-bold"><?= date('d/m/Y', strtotime($appointment['appointment_date'])) ?> às <?= substr($appointment['appointment_time'], 0, 5) ?></td>
                                    <td class="px-4 py-3">
                                        <?= e($appointment['client_name']) ?>
                                        <?php if ($appointment['notes']): ?>
                                            <small class="mt-1 block text-slate-500"><?= e($appointment['notes']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3"><?= e($appointment['service_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($appointment['manicure_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($appointment['client_phone']) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-md bg-pink-50 px-2 py-1 font-bold text-pink-700"><?= e($appointment['status']) ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <form method="post" class="flex gap-2">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_appointment">
                                            <input type="hidden" name="id" value="<?= (int)$appointment['id'] ?>">
                                            <select name="status" class="rounded-md border border-slate-300 px-2 py-1">
                                                <?php foreach (['marcado', 'confirmado', 'concluido', 'cancelado'] as $status): ?>
                                                    <option value="<?= e($status) ?>" <?= selected($appointment['status'], $status) ?>><?= e($status) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="rounded-md bg-slate-900 px-3 py-1 font-bold text-white">Salvar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$appointments): ?>
                                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Nenhum agendamento encontrado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($page === 'sobre'): ?>
            <section class="grid gap-6 lg:grid-cols-[1fr_360px]">
                <form method="post" enctype="multipart/form-data" class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <h1 class="text-3xl font-black text-slate-950">Sobre mim</h1>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_about">
                    <input type="hidden" name="image_zoom" value="<?= e((string)$aboutImageZoom) ?>" data-about-input="image_zoom">
                    <input type="hidden" name="image_pos_x" value="<?= e((string)$aboutImagePosX) ?>" data-about-input="image_pos_x">
                    <input type="hidden" name="image_pos_y" value="<?= e((string)$aboutImagePosY) ?>" data-about-input="image_pos_y">
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="block sm:col-span-2">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Trocar foto</span>
                            <input data-about-input="image_file" name="about_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full rounded-md border border-slate-300 px-3 py-2 file:mr-3 file:rounded-md file:border-0 file:bg-pink-50 file:px-3 file:py-2 file:font-bold file:text-pink-700">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="mb-1 block text-sm font-bold text-slate-700">URL da foto</span>
                            <input data-about-input="image_url" name="image_url" value="<?= e($aboutImage) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Titulo pequeno</span>
                            <input data-about-input="label" name="label" value="<?= e($aboutLabel) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Assinatura</span>
                            <input data-about-input="signature" name="signature" value="<?= e($aboutSignature) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Texto</span>
                            <textarea data-about-input="text" name="text" rows="5" class="w-full rounded-md border border-slate-300 px-3 py-2"><?= e($aboutText) ?></textarea>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Fonte do texto</span>
                            <select data-about-input="text_font" name="text_font" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                <option value="brand-serif" <?= selected($aboutTextFont, 'brand-serif') ?>>Elegante</option>
                                <option value="brand-script" <?= selected($aboutTextFont, 'brand-script') ?>>Assinatura</option>
                                <option value="font-sans" <?= selected($aboutTextFont, 'font-sans') ?>>Simples</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Fonte da assinatura</span>
                            <select data-about-input="signature_font" name="signature_font" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                <option value="brand-script" <?= selected($aboutSignatureFont, 'brand-script') ?>>Assinatura</option>
                                <option value="brand-serif" <?= selected($aboutSignatureFont, 'brand-serif') ?>>Elegante</option>
                                <option value="font-sans" <?= selected($aboutSignatureFont, 'font-sans') ?>>Simples</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Cor do titulo</span>
                            <input data-about-input="label_color" type="color" name="label_color" value="<?= e($aboutLabelColor) ?>" class="h-11 w-full rounded-md border border-slate-300 px-2 py-1">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Cor do texto</span>
                            <input data-about-input="text_color" type="color" name="text_color" value="<?= e($aboutTextColor) ?>" class="h-11 w-full rounded-md border border-slate-300 px-2 py-1">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-bold text-slate-700">Cor da assinatura</span>
                            <input data-about-input="signature_color" type="color" name="signature_color" value="<?= e($aboutSignatureColor) ?>" class="h-11 w-full rounded-md border border-slate-300 px-2 py-1">
                        </label>
                        <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700 sm:col-span-2">Salvar Sobre mim</button>
                    </div>
                </form>
                <aside class="self-start rounded-2xl border border-rose-200 bg-white/90 p-2 shadow-sm">
                    <div data-about-frame class="relative h-[370px] w-full cursor-grab touch-none overflow-hidden rounded-xl bg-rose-50">
                        <img data-about-preview="image" class="pointer-events-none absolute inset-0 h-full w-full select-none object-cover object-center" draggable="false" style="transform: <?= e($aboutImageTransform) ?>;" src="<?= e($aboutImage) ?>" alt="<?= e($aboutSignature) ?>">
                    </div>
                    <div data-about-preview="body" class="px-6 py-7 text-center" style="color: <?= e($aboutTextColor) ?>">
                        <p data-about-preview="label" class="text-xs font-bold uppercase tracking-wide" style="color: <?= e($aboutLabelColor) ?>"><?= e($aboutLabel) ?></p>
                        <p data-about-preview="text" class="<?= e($aboutTextFont) ?> mt-4 text-lg leading-relaxed"><?= e($aboutText) ?></p>
                        <p data-about-preview="signature" class="<?= e($aboutSignatureFont) ?> mt-6 text-3xl" style="color: <?= e($aboutSignatureColor) ?>"><?= e($aboutSignature) ?></p>
                    </div>
                </aside>
            </section>
        <?php elseif ($page === 'usuarios'): ?>
            <?php
            $users = $pdo->query('SELECT id, name, email, phone, role, created_at FROM users ORDER BY name')->fetchAll();
            ?>
            <section class="grid gap-6 lg:grid-cols-[.8fr_1.2fr]">
                <form method="post" class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <h1 class="text-2xl font-black text-slate-950">Cadastrar usuário</h1>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_user">
                    <div class="mt-5 space-y-4">
                        <input required name="name" placeholder="Nome" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        <input required type="email" name="email" placeholder="E-mail" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        <input name="phone" placeholder="WhatsApp" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        <input required type="password" name="password" placeholder="Senha inicial" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        <select name="role" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            <option value="manicure">Manicure</option>
                            <option value="manicure_admin">Manicure admin</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button class="w-full rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Salvar usuário</button>
                    </div>
                </form>
                <div class="rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                    <h2 class="text-2xl font-black text-slate-950">Usuários</h2>
                    <div class="mt-4 divide-y divide-slate-100">
                        <?php foreach ($users as $user): ?>
                            <div class="py-3">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <strong><?= e($user['name']) ?></strong>
                                        <small class="block text-slate-500"><?= e($user['email']) ?> &middot; <?= e(role_label($user['role'])) ?></small>
                                        <?php if ($user['phone']): ?>
                                            <a class="mt-1 block text-sm font-bold text-pink-700 hover:underline" href="<?= e(whatsapp_link($user['phone'])) ?>" target="_blank" rel="noopener"><?= e($user['phone']) ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <button type="button" data-edit-user="<?= (int)$user['id'] ?>" class="rounded-md border border-pink-200 px-3 py-2 text-sm font-bold text-pink-700 hover:bg-pink-50">Editar</button>
                                        <form method="post" onsubmit="return confirm('Apagar este usuario?')">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                            <button class="rounded-md border border-red-200 px-3 py-2 text-sm font-bold text-red-700 hover:bg-red-50">Apagar</button>
                                        </form>
                                    </div>
                                </div>
                                <form method="post" data-edit-user-form="<?= (int)$user['id'] ?>" class="mt-3 hidden grid gap-3 rounded-md border border-pink-100 bg-pink-50/50 p-3 sm:grid-cols-2">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-bold text-slate-700">Nome</span>
                                        <input required name="name" value="<?= e($user['name']) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-bold text-slate-700">E-mail</span>
                                        <input required type="email" name="email" value="<?= e($user['email']) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-bold text-slate-700">WhatsApp</span>
                                        <input name="phone" value="<?= e($user['phone'] ?? '') ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-bold text-slate-700">Tipo</span>
                                        <select name="role" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                            <option value="manicure" <?= $user['role'] === 'manicure' ? 'selected' : '' ?>>Manicure</option>
                                            <option value="manicure_admin" <?= $user['role'] === 'manicure_admin' ? 'selected' : '' ?>>Manicure admin</option>
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="mb-1 block text-xs font-bold text-slate-700">Nova senha</span>
                                        <input type="password" name="password" placeholder="Deixe vazio para manter a senha atual" class="w-full rounded-md border border-slate-300 px-3 py-2">
                                    </label>
                                    <button class="rounded-lg bg-slate-900 px-4 py-2 font-bold text-white sm:col-span-2">Salvar alteracoes</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php elseif ($page === 'servicos'): ?>
            <?php
            $allServices = $pdo->query('SELECT * FROM services ORDER BY active DESC, name')->fetchAll();
            ?>
            <section>
                <h1 class="text-3xl font-black text-slate-950">Serviços do catálogo</h1>
                <form method="post" enctype="multipart/form-data" class="mt-5 grid gap-4 rounded-lg border border-rose-100 bg-white p-6 shadow-sm lg:grid-cols-6">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_service">
                    <label class="block lg:col-span-2">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Nome do serviço</span>
                        <input name="name" required placeholder="Ex: Alongamento na tips" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Valor</span>
                        <input name="price" required placeholder="Ex: 115,00" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Valor com decoração</span>
                        <input name="price_decorated" required placeholder="Ex: 125,00" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Valor com decoração</span>
                        <input name="price_decorated" required placeholder="Ex: 125,00" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Duração em minutos</span>
                        <input name="duration_minutes" type="number" value="30" min="15" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block lg:col-span-2">
                        <span class="mb-1 block text-sm font-bold text-slate-700">URL da foto</span>
                        <input name="image_url" placeholder="Cole um link de imagem, se quiser" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block lg:col-span-3">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Enviar foto do dispositivo</span>
                        <input name="service_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full rounded-md border border-slate-300 px-3 py-2 file:mr-3 file:rounded-md file:border-0 file:bg-pink-50 file:px-3 file:py-2 file:font-bold file:text-pink-700">
                    </label>
                    <?= service_image_adjuster('new') ?>
                    <label class="block lg:col-span-2">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Status</span>
                        <span class="flex min-h-[42px] items-center gap-2 font-bold"><input type="checkbox" name="active" checked> Ativo</span>
                    </label>
                    <label class="block lg:col-span-6">
                        <span class="mb-1 block text-sm font-bold text-slate-700">Descrição</span>
                        <textarea name="description" placeholder="Ex: Gel." class="w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                    </label>
                    <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700 lg:col-span-6">Cadastrar serviço</button>
                </form>

                <div class="mt-6 grid gap-4">
                    <?php foreach ($allServices as $service): ?>
                        <form method="post" enctype="multipart/form-data" class="grid gap-3 rounded-lg border border-rose-100 bg-white p-4 shadow-sm lg:grid-cols-6">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$service['id'] ?>">
                            <input type="hidden" name="action" value="save_service">
                            <label class="block lg:col-span-2">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Nome do serviço</span>
                                <input name="name" value="<?= e($service['name']) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Valor</span>
                                <input name="price" value="<?= e((string)$service['price']) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Valor com decoração</span>
                                <input name="price_decorated" value="<?= e(number_format((float)($service['price_decorated'] ?? 0), 2, ',', '.')) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Duração em minutos</span>
                                <input name="duration_minutes" type="number" min="15" value="<?= (int)$service['duration_minutes'] ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block lg:col-span-2">
                                <span class="mb-1 block text-sm font-bold text-slate-700">URL da foto</span>
                                <input name="image_url" value="<?= e($service['image_url']) ?>" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block lg:col-span-3">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Trocar foto pelo dispositivo</span>
                                <input name="service_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full rounded-md border border-slate-300 px-3 py-2 file:mr-3 file:rounded-md file:border-0 file:bg-pink-50 file:px-3 file:py-2 file:font-bold file:text-pink-700">
                            </label>
                            <?= service_image_adjuster('service-' . (int)$service['id'], $service) ?>
                            <label class="block lg:col-span-2">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Status</span>
                                <span class="flex min-h-[42px] items-center gap-2 font-bold"><input type="checkbox" name="active" <?= (int)$service['active'] === 1 ? 'checked' : '' ?>> Ativo</span>
                            </label>
                            <label class="block lg:col-span-6">
                                <span class="mb-1 block text-sm font-bold text-slate-700">Descrição</span>
                                <textarea name="description" class="w-full rounded-md border border-slate-300 px-3 py-2"><?= e($service['description']) ?></textarea>
                            </label>
                            <button class="rounded-lg bg-slate-900 px-4 py-2 font-bold text-white lg:col-span-3">Salvar</button>
                            <button formaction="" name="action" value="delete_service" onclick="return confirm('Apagar este serviço?')" class="rounded-lg border border-red-200 px-4 py-2 font-bold text-red-700 hover:bg-red-50 lg:col-span-3">Apagar</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="rounded-lg border border-rose-100 bg-white p-8 text-center shadow-sm">
                <h1 class="text-2xl font-black">Página não encontrada</h1>
                <a class="mt-4 inline-block rounded-lg bg-pink-600 px-5 py-3 font-bold text-white" href="?page=inicio">Voltar</a>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($page === 'agendar'): ?>
        <script>
            const availability = <?= json_encode($availabilityByManicure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const manicureSelect = document.getElementById('manicure_id');
            const dateSelect = document.getElementById('appointment_date');
            const timeSelect = document.getElementById('appointment_time');

            function resetSelect(select, label) {
                select.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = label;
                select.appendChild(option);
            }

            function formatDate(date) {
                const [year, month, day] = date.split('-');
                return `${day}/${month}/${year}`;
            }

            function updateDates() {
                const manicureId = manicureSelect.value;
                resetSelect(dateSelect, 'Selecione');
                resetSelect(timeSelect, 'Escolha uma data primeiro');

                if (!manicureId || !availability[manicureId]) {
                    resetSelect(dateSelect, 'Nenhuma data disponível');
                    return;
                }

                Object.keys(availability[manicureId]).forEach((date) => {
                    const option = document.createElement('option');
                    option.value = date;
                    option.textContent = formatDate(date);
                    dateSelect.appendChild(option);
                });
            }

            function updateTimes() {
                const manicureId = manicureSelect.value;
                const date = dateSelect.value;
                resetSelect(timeSelect, 'Selecione');

                if (!manicureId || !date || !availability[manicureId]?.[date]) {
                    resetSelect(timeSelect, 'Nenhum horário disponível');
                    return;
                }

                availability[manicureId][date].forEach((time) => {
                    const option = document.createElement('option');
                    option.value = time;
                    option.textContent = time;
                    timeSelect.appendChild(option);
                });
            }

            manicureSelect.addEventListener('change', updateDates);
            dateSelect.addEventListener('change', updateTimes);
            updateDates();
        </script>
    <?php endif; ?>
    <?php if ($page === 'marketing'): ?>
        <script>
            const marketingServices = <?= json_encode($marketingServices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const serviceSelect = document.getElementById('marketing_service');
            const captionInput = document.getElementById('marketing_caption');
            const canvas = document.getElementById('marketing_canvas');
            const ctx = canvas.getContext('2d');
            const downloadButton = document.getElementById('download_marketing_art');

            function money(value) {
                return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            }

            function wrapText(text, x, y, maxWidth, lineHeight) {
                const words = String(text || '').split(/\s+/);
                let line = '';
                words.forEach((word) => {
                    const testLine = line ? `${line} ${word}` : word;
                    if (ctx.measureText(testLine).width > maxWidth && line) {
                        ctx.fillText(line, x, y);
                        line = word;
                        y += lineHeight;
                    } else {
                        line = testLine;
                    }
                });
                if (line) {
                    ctx.fillText(line, x, y);
                }
                return y;
            }

            function drawFallback(service) {
                const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
                gradient.addColorStop(0, '#fff1f7');
                gradient.addColorStop(1, '#ffffff');
                ctx.fillStyle = gradient;
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                drawText(service);
            }

            function drawText(service) {
                ctx.fillStyle = 'rgba(255,255,255,.92)';
                ctx.fillRect(80, 1160, 920, 560);
                ctx.fillStyle = '#be185d';
                ctx.font = 'bold 42px Arial';
                ctx.fillText('Samara Eduarda Nail Designer', 110, 1240);
                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 76px Arial';
                wrapText(service?.name || 'Serviço do catálogo', 110, 1360, 860, 86);
                ctx.fillStyle = '#be185d';
                ctx.font = 'bold 64px Arial';
                const priceNormal = money(service?.price);
                const priceDeco = money(service?.price_decorated);
                ctx.fillText(`Sem: ${priceNormal} / Com: ${priceDeco}`, 110, 1550);
                ctx.fillStyle = '#334155';
                ctx.font = '40px Arial';
                wrapText(captionInput.value || 'Agenda aberta. Garanta seu horário pelo WhatsApp.', 110, 1640, 860, 52);
            }

            function renderMarketingArt() {
                const service = marketingServices.find((item) => String(item.id) === serviceSelect.value) || marketingServices[0];
                if (!service?.image_url) {
                    drawFallback(service);
                    return;
                }

                const image = new Image();
                image.crossOrigin = 'anonymous';
                image.onload = () => {
                    ctx.fillStyle = '#fff1f7';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    const scale = Math.max(canvas.width / image.width, canvas.height / image.height);
                    const width = image.width * scale;
                    const height = image.height * scale;
                    ctx.drawImage(image, (canvas.width - width) / 2, (canvas.height - height) / 2, width, height);
                    ctx.fillStyle = 'rgba(15,23,42,.22)';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    drawText(service);
                };
                image.onerror = () => drawFallback(service);
                image.src = service.image_url;
            }

            serviceSelect.addEventListener('change', renderMarketingArt);
            captionInput.addEventListener('input', renderMarketingArt);
            downloadButton.addEventListener('click', () => {
                try {
                    const link = document.createElement('a');
                    link.download = 'post-samara-eduarda.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                } catch (error) {
                    alert('Não foi possível baixar essa imagem. Tente usar uma foto enviada pelo dispositivo no serviço.');
                }
            });
            renderMarketingArt();
        </script>
    <?php endif; ?>
    <?php if ($page === 'usuarios'): ?>
        <script>
            document.querySelectorAll('[data-edit-user]').forEach((button) => {
                button.addEventListener('click', () => {
                    const form = document.querySelector(`[data-edit-user-form="${button.dataset.editUser}"]`);
                    if (!form) {
                        return;
                    }
                    form.classList.toggle('hidden');
                    button.textContent = form.classList.contains('hidden') ? 'Editar' : 'Fechar';
                });
            });
        </script>
    <?php endif; ?>
    <?php if ($page === 'sobre'): ?>
        <script>
            const aboutInputs = {
                imageFile: document.querySelector('[data-about-input="image_file"]'),
                imageUrl: document.querySelector('[data-about-input="image_url"]'),
                label: document.querySelector('[data-about-input="label"]'),
                text: document.querySelector('[data-about-input="text"]'),
                signature: document.querySelector('[data-about-input="signature"]'),
                textFont: document.querySelector('[data-about-input="text_font"]'),
                signatureFont: document.querySelector('[data-about-input="signature_font"]'),
                labelColor: document.querySelector('[data-about-input="label_color"]'),
                textColor: document.querySelector('[data-about-input="text_color"]'),
                signatureColor: document.querySelector('[data-about-input="signature_color"]'),
                imageZoom: document.querySelector('[data-about-input="image_zoom"]'),
                imagePosX: document.querySelector('[data-about-input="image_pos_x"]'),
                imagePosY: document.querySelector('[data-about-input="image_pos_y"]'),
            };
            const aboutPreview = {
                image: document.querySelector('[data-about-preview="image"]'),
                frame: document.querySelector('[data-about-frame]'),
                body: document.querySelector('[data-about-preview="body"]'),
                label: document.querySelector('[data-about-preview="label"]'),
                text: document.querySelector('[data-about-preview="text"]'),
                signature: document.querySelector('[data-about-preview="signature"]'),
            };
            const fontClasses = ['brand-serif', 'brand-script', 'font-sans'];
            let aboutImageZoom = Number(aboutInputs.imageZoom.value || 1);
            let aboutImagePosX = Number(aboutInputs.imagePosX.value || 0);
            let aboutImagePosY = Number(aboutInputs.imagePosY.value || 0);
            let aboutDragging = false;
            let aboutDragStartX = 0;
            let aboutDragStartY = 0;
            let aboutDragBaseX = 0;
            let aboutDragBaseY = 0;

            function clamp(value, min, max) {
                return Math.max(min, Math.min(max, value));
            }

            function setFont(element, fontClass) {
                element.classList.remove(...fontClasses);
                element.classList.add(fontClass);
            }

            function updateAboutImageTransform() {
                aboutImagePosX = clamp(aboutImagePosX, -45, 45);
                aboutImagePosY = clamp(aboutImagePosY, -45, 45);
                aboutInputs.imageZoom.value = String(Number(aboutImageZoom).toFixed(2));
                aboutInputs.imagePosX.value = String(Math.round(aboutImagePosX));
                aboutInputs.imagePosY.value = String(Math.round(aboutImagePosY));
                aboutPreview.image.style.transform = `translate(${aboutImagePosX}%, ${aboutImagePosY}%) scale(${aboutImageZoom})`;
            }

            function updateAboutPreview() {
                aboutPreview.label.textContent = aboutInputs.label.value || 'Sobre mim';
                aboutPreview.text.textContent = aboutInputs.text.value || '';
                aboutPreview.signature.textContent = aboutInputs.signature.value || '';
                aboutPreview.label.style.color = aboutInputs.labelColor.value;
                aboutPreview.body.style.color = aboutInputs.textColor.value;
                aboutPreview.signature.style.color = aboutInputs.signatureColor.value;
                setFont(aboutPreview.text, aboutInputs.textFont.value);
                setFont(aboutPreview.signature, aboutInputs.signatureFont.value);
                updateAboutImageTransform();
            }

            [
                aboutInputs.label,
                aboutInputs.text,
                aboutInputs.signature,
                aboutInputs.textFont,
                aboutInputs.signatureFont,
                aboutInputs.labelColor,
                aboutInputs.textColor,
                aboutInputs.signatureColor,
            ].forEach((input) => {
                input.addEventListener('input', updateAboutPreview);
                input.addEventListener('change', updateAboutPreview);
            });

            aboutInputs.imageUrl.addEventListener('input', () => {
                if (aboutInputs.imageUrl.value.trim() !== '') {
                    aboutPreview.image.src = aboutInputs.imageUrl.value.trim();
                }
            });

            aboutInputs.imageFile.addEventListener('change', () => {
                const file = aboutInputs.imageFile.files?.[0];
                if (!file) {
                    return;
                }
                aboutPreview.image.src = URL.createObjectURL(file);
                aboutImageZoom = 1;
                aboutImagePosX = 0;
                aboutImagePosY = 0;
                updateAboutImageTransform();
            });

            aboutPreview.frame.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                aboutDragging = true;
                aboutDragStartX = event.clientX;
                aboutDragStartY = event.clientY;
                aboutDragBaseX = aboutImagePosX;
                aboutDragBaseY = aboutImagePosY;
                aboutPreview.frame.setPointerCapture(event.pointerId);
                aboutPreview.frame.classList.add('cursor-grabbing');
            });

            aboutPreview.frame.addEventListener('pointermove', (event) => {
                if (!aboutDragging) {
                    return;
                }
                const rect = aboutPreview.frame.getBoundingClientRect();
                aboutImagePosX = clamp(aboutDragBaseX + ((event.clientX - aboutDragStartX) / rect.width) * 70, -45, 45);
                aboutImagePosY = clamp(aboutDragBaseY + ((event.clientY - aboutDragStartY) / rect.height) * 70, -45, 45);
                updateAboutImageTransform();
            });

            function stopAboutDragging(event) {
                if (!aboutDragging) {
                    return;
                }
                aboutDragging = false;
                aboutPreview.frame.releasePointerCapture?.(event.pointerId);
                aboutPreview.frame.classList.remove('cursor-grabbing');
            }

            aboutPreview.frame.addEventListener('pointerup', stopAboutDragging);
            aboutPreview.frame.addEventListener('pointercancel', stopAboutDragging);
            aboutPreview.frame.addEventListener('wheel', (event) => {
                event.preventDefault();
                aboutImageZoom = clamp(aboutImageZoom + (event.deltaY < 0 ? 0.08 : -0.08), 0.5, 4);
                updateAboutImageTransform();
            }, { passive: false });

            updateAboutPreview();
        </script>
    <?php endif; ?>
    <?php if ($page === 'servicos'): ?>
        <script>
            document.querySelectorAll('.service-image-adjuster').forEach((adjuster) => {
                const form = adjuster.closest('form');
                const image = adjuster.querySelector('[data-adjuster-image]');
                const zoom = adjuster.querySelector('[data-adjuster-zoom]');
                const zoomInput = adjuster.querySelector('[data-adjuster-zoom-input]');
                const posXInput = adjuster.querySelector('[data-adjuster-x-input]');
                const posYInput = adjuster.querySelector('[data-adjuster-y-input]');
                const rotationInput = adjuster.querySelector('[data-adjuster-rotation-input]');
                const okButton = adjuster.querySelector('[data-adjuster-ok]');
                const rotateLeft = adjuster.querySelector('[data-adjuster-rotate-left]');
                const rotateRight = adjuster.querySelector('[data-adjuster-rotate-right]');
                const frame = adjuster.querySelector('.service-image-frame');
                const fileInput = form?.querySelector('input[type="file"][name="service_image"]');
                const urlInput = form?.querySelector('input[name="image_url"]');
                let posX = Number(posXInput.value || 0);
                let posY = Number(posYInput.value || 0);
                let rotation = Number(rotationInput.value || 0);
                let isDragging = false;
                let dragStartX = 0;
                let dragStartY = 0;
                let dragBaseX = 0;
                let dragBaseY = 0;

                function clamp(value, min, max) {
                    return Math.max(min, Math.min(max, value));
                }

                function renderAdjustment() {
                    zoomInput.value = zoom.value;
                    posXInput.value = String(Math.round(posX));
                    posYInput.value = String(Math.round(posY));
                    rotationInput.value = String(rotation);
                    image.style.transform = `translate(${posX}%, ${posY}%) rotate(${rotation}deg) scale(${zoom.value})`;
                    if (okButton) {
                        okButton.textContent = 'OK, usar este ajuste';
                        okButton.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                        okButton.classList.add('bg-pink-600', 'hover:bg-pink-700');
                    }
                }

                zoom.addEventListener('input', renderAdjustment);
                rotateLeft?.addEventListener('click', () => {
                    rotation = (rotation + 270) % 360;
                    renderAdjustment();
                });
                rotateRight?.addEventListener('click', () => {
                    rotation = (rotation + 90) % 360;
                    renderAdjustment();
                });

                frame?.addEventListener('pointerdown', (event) => {
                    isDragging = true;
                    dragStartX = event.clientX;
                    dragStartY = event.clientY;
                    dragBaseX = posX;
                    dragBaseY = posY;
                    frame.setPointerCapture(event.pointerId);
                    frame.classList.add('cursor-grabbing');
                });

                frame?.addEventListener('pointermove', (event) => {
                    if (!isDragging) {
                        return;
                    }
                    const rect = frame.getBoundingClientRect();
                    posX = clamp(dragBaseX + ((event.clientX - dragStartX) / rect.width) * 100, -100, 100);
                    posY = clamp(dragBaseY + ((event.clientY - dragStartY) / rect.height) * 100, -100, 100);
                    renderAdjustment();
                });

                function stopDragging(event) {
                    if (!isDragging) {
                        return;
                    }
                    isDragging = false;
                    frame?.releasePointerCapture?.(event.pointerId);
                    frame?.classList.remove('cursor-grabbing');
                }

                frame?.addEventListener('pointerup', stopDragging);
                frame?.addEventListener('pointercancel', stopDragging);
                frame?.addEventListener('wheel', (event) => {
                    event.preventDefault();
                    const nextZoom = Number(zoom.value) + (event.deltaY < 0 ? 0.08 : -0.08);
                    zoom.value = String(clamp(nextZoom, Number(zoom.min), Number(zoom.max)).toFixed(2));
                    renderAdjustment();
                }, { passive: false });

                fileInput?.addEventListener('change', () => {
                    const file = fileInput.files?.[0];
                    if (!file) {
                        return;
                    }
                    image.src = URL.createObjectURL(file);
                    zoom.value = '1';
                    posX = 0;
                    posY = 0;
                    rotation = 0;
                    renderAdjustment();
                    adjuster.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });

                urlInput?.addEventListener('input', () => {
                    if (urlInput.value.trim() !== '') {
                        image.src = urlInput.value.trim();
                    }
                });

                okButton?.addEventListener('click', () => {
                    renderAdjustment();
                    okButton.textContent = 'Ajuste aplicado';
                    okButton.classList.remove('bg-pink-600', 'hover:bg-pink-700');
                    okButton.classList.add('bg-emerald-600', 'hover:bg-emerald-700');
                });

                renderAdjustment();
            });
        </script>
    <?php endif; ?>
</body>
</html>
