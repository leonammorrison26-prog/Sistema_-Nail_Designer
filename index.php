<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notifications.php';

// CHAVINHA PARA ATIVAR/DESATIVAR O TEMPO DE ATENDIMENTO
// Altere para true para ATIVAR ou false para OCULTAR/DESATIVAR
$exibir_tempo_atendimento = false; 

try {
    ensure_schema();
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<h1>Erro ao conectar no MySQL</h1>';
    echo '<p>Confira as variáveis DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS ou DATABASE_URL.</p>';
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
        redirect_with('servicos', 'Não foi possível enviar a foto. Tente novamente.', 'error');
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

    // CORREÇÃO ROBUSTA DE DIRETÓRIOS
    $assetsDir = __DIR__ . '/assets';
    $uploadDir = $assetsDir . '/uploads';

    if (!is_dir($assetsDir)) {
        mkdir($assetsDir, 0775, true);
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = 'servico-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        redirect_with('servicos', 'Não foi possível salvar a foto enviada.', 'error');
    }

    return 'assets/uploads/' . $filename;
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
        $role = $_POST['role'] === 'admin' ? 'admin' : 'manicure';

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
        $configPath = __DIR__ . '/config.xml';
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

        $xml->asXML($configPath);
        redirect_with('redes', 'Redes sociais updated.');
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
        $uploadedImage = uploaded_service_image();

        $data = [
            trim($_POST['name'] ?? ''),
            trim($_POST['description'] ?? ''),
            (float)$normalizedPrice,
            max(15, (int)($_POST['duration_minutes'] ?? 30)),
            $uploadedImage ?? trim($_POST['image_url'] ?? ''),
            isset($_POST['active']) ? 1 : 0,
        ];

        if ($data[0] === '') {
            redirect_with('servicos', 'O nome do serviço é obrigatório.', 'error');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare('
                UPDATE services
                SET name = ?, description = ?, price = ?, duration_minutes = ?, image_url = ?, active = ?
                WHERE id = ?
            ');
            $stmt->execute([...$data, $id]);
            redirect_with('servicos', 'Serviço atualizado.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO services (name, description, price, duration_minutes, image_url, active)
            VALUES (?, ?, ?, ?, ?, ?)
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
        redirect_with('agenda', 'Agendamento updated.');
    }
}

if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=inicio');
    exit;
}

$loginPages = ['agenda'];
$adminPages = ['marketing', 'usuarios', 'servicos'];

if (in_array($page, $adminPages, true)) {
    require_admin();
} elseif (in_array($page, $loginPages, true)) {
    require_login();
}

$services = $pdo->query('SELECT * FROM services WHERE active = 1 ORDER BY name')->fetchAll();
$manicures = $pdo->query("SELECT id, name FROM users WHERE role = 'manicure' ORDER BY name")->fetchAll();
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

function nav_link(string $target, string $label, string $current): string
{
    $active = $target === $current ? 'bg-pink-600 text-white shadow-sm' : 'text-stone-950 hover:bg-rose-50 hover:text-pink-700';
    return '<a class="rounded-md px-5 py-3 text-sm font-semibold transition ' . $active . '" href="?page=' . e($target) . '">' . e($label) . '</a>';
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
                        <img class="h-[370px] w-full rounded-xl object-cover object-center" src="assets/01.jpeg" alt="Samara Eduarda Nail Designer">
                        <div class="px-6 py-7 text-center text-[#743632]">
                            <p class="text-xs font-bold uppercase tracking-wide text-[#bd665d]">Sobre mim</p>
                            <p class="brand-serif mt-4 text-lg leading-relaxed">Olá! Sou Samara Eduarda, Nail Designer especializada em manicure, pedicure e cuidados que valorizam a beleza das suas unhas.</p>
                            <p class="brand-script mt-6 text-3xl text-[#a65a54]">Samara Eduarda</p>
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
                        <article class="overflow-hidden rounded-lg border border-rose-100 bg-white text-center shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                            <img class="h-32 w-full object-cover" src="<?= e($service['image_url'] ?: 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=700&q=85') ?>" alt="<?= e($service['name']) ?>">
                            <div class="p-5">
                                <h3 class="brand-serif text-2xl font-bold text-stone-950"><?= e($service['name']) ?></h3>
                                <p class="mt-1 min-h-10 text-sm leading-relaxed text-stone-700"><?= e($service['description']) ?></p>
                                
                                <div class="mt-3 text-xs text-stone-600 font-medium space-y-0.5">
                                    <div>Sem decoração: <span class="text-pink-700 font-bold">115,00</span></div>
                                    <div>Com decoração: <span class="text-pink-700 font-bold">125,00</span></div>
                                </div>

                                <div class="mt-4 flex items-center justify-center gap-2 text-sm">
                                    <span class="rounded-md bg-pink-50 px-3 py-1 font-black text-pink-700"><?= money_br($service['price']) ?></span>
                                    
                                    <?php if ($exibir_tempo_atendimento): ?>
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
                    <div class="flex min-h-12 flex-col justify-center border-b border-rose-100 px-4 py-2 sm:border-b-0 sm:border-r lg:px-5">
                        <strong class="brand-serif flex flex-wrap items-baseline gap-1.5 text-lg font-bold leading-none text-stone-950 lg:text-xl">
                            <span class="tracking-[1px]" aria-label="5 estrelas">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                            <span>5.0</span>
                        </strong>
                        <span class="mt-1 text-sm leading-tight text-[#7b3935]">Nossas clientes recomendam</span>
                    </div>
                    <div class="flex min-h-12 flex-col justify-center border-b border-rose-100 px-4 py-2 sm:border-b-0 sm:border-r lg:px-5">
                        <strong class="brand-serif block text-xl font-bold leading-none text-stone-950 lg:text-2xl">+300</strong>
                        <span class="mt-1 text-sm leading-tight text-[#7b3935]">atendimentos realizados</span>
                    </div>
                    <div class="flex min-h-12 flex-col justify-center px-4 py-2 lg:px-5">
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
                                        <?= e($service['name']) ?> - <?= money_br($service['price']) ?>
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
            <?php $socialLinks = [
                'WhatsApp' => whatsapp_link((string)$config->salon->phone),
                'Instagram' => trim((string)($config->social->instagram ?? '')),
                'Facebook' => trim((string)($config->social->facebook ?? '')),
                'TikTok' => trim((string)($config->social->tiktok ?? '')),
            ]; ?>
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
                            <button class="rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Salvar redes sociais</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($page === 'login'): ?>
            <div class="mx-auto max-w-md rounded-lg border border-rose-100 bg-white p-6 shadow-sm">
                <h1 class="text-2xl font-black text-slate-950">Acesso Restrito</h1>
                <p class="mt-1 text-sm text-slate-600">Área exclusiva para manicures e administradores.</p>
                <form method="post" class="mt-5 grid gap-4">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <label class="block">
                        <span class="text-sm font-bold text-slate-700">E-mail</span>
                        <input type="email" required name="email" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="text-sm font-bold text-slate-700">Senha</span>
                        <input type="password" required name="password" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                    <button class="mt-2 rounded-lg bg-pink-600 px-5 py-3 font-black text-white hover:bg-pink-700">Entrar no painel</button>
                </form>
            </div>
        <?php elseif ($page === 'agenda'): ?>
            <?php
            $selectedManicureId = is_admin() ? (int)($_GET['manicure_id'] ?? current_user()['id']) : (int)current_user()['id'];
            $appointmentsSql = "
                SELECT a.*, s.name AS service_name, s.price AS service_price, u.name AS manicure_name
                FROM appointments a
                JOIN services s ON s.id = a.service_id
                JOIN users u ON u.id = a.manicure_id
                WHERE a.appointment_date >= CURDATE()
            ";
            $appointmentsParams = [];
            if (!is_admin()) {
                $appointmentsSql .= " AND a.manicure_id = ?";
                $appointmentsParams[] = $selectedManicureId;
            } else if (isset($_GET['manicure_id']) && $_GET['manicure_id'] !== '') {
                $appointmentsSql .= " AND a.manicure_id = ?";
                $appointmentsParams[] = $selectedManicureId;
            }
            $appointmentsSql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";
            $stmt = $pdo->prepare($appointmentsSql);
            $stmt->execute($appointmentsParams);
            $agendaAppointments = $stmt->fetchAll();

            $availSql = "SELECT ma.*, TIME_FORMAT(ma.available_time, '%H:%i') AS formatted_time FROM manicure_availability ma WHERE ma.available_date >= CURDATE()";
            $availParams = [];
            if (!is_admin()) {
                $availSql .= " AND ma.manicure_id = ?";
                $availParams[] = $selectedManicureId;
            } else if (isset($_GET['manicure_id']) && $_GET['manicure_id'] !== '') {
                $availSql .= " AND ma.manicure_id = ?";
                $availParams[] = $selectedManicureId;
            }
            $availSql .= " ORDER BY ma.available_date ASC, ma.available_time ASC";
            $stmt = $pdo->prepare($availSql);
            $stmt->execute($availParams);
            $myAvailability = $stmt->fetchAll();
            ?>
            <h1 class="text-3xl font-black text-slate-950">Controle da Agenda</h1>
            <p class="mt-1 text-slate-600">Gerencie os horários marcados pelas clientes e sua disponibilidade de atendimento.</p>

            <?php if (is_admin()): ?>
                <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <form method="get" class="flex flex-wrap items-center gap-3">
                        <input type="hidden" name="page" value="agenda">
                        <label class="text-sm font-bold text-slate-700">Filtrar por Profissional:</label>
                        <select name="manicure_id" onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                            <?php foreach ($manicures as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= selected((string)$selectedManicureId, (string)$m['id']) ?>><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>

            <div class="mt-8 grid gap-8 lg:grid-cols-[1.3fr_.7fr]">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Horários Agendados</h2>
                    <div class="mt-4 space-y-3">
                        <?php if ($agendaAppointments === []): ?>
                            <p class="text-sm italic text-slate-500">Nenhum agendamento futuro encontrado.</p>
                        <?php else: ?>
                            <?php foreach ($agendaAppointments as $app): ?>
                                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-wrap justify-between items-center gap-4">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-black text-slate-950"><?= e($app['client_name']) ?></span>
                                            <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?= $app['status'] === 'confirmado' ? 'bg-emerald-100 text-emerald-800' : ($app['status'] === 'cancelado' ? 'bg-red-100 text-red-800' : ($app['status'] === 'concluido' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) ?>">
                                                <?= e($app['status']) ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-600 space-y-0.5">
                                            <p>🗓️ <strong><?= date('d/m/Y', strtotime($app['appointment_date'])) ?> às <?= date('H:i', strtotime($app['appointment_time'])) ?></strong></p>
                                            <p>💅 Serviço: <?= e($app['service_name']) ?> (<?= money_br($app['service_price']) ?>)</p>
                                            <p>📱 WhatsApp: <a class="text-pink-600 hover:underline font-semibold" href="<?= e(whatsapp_link($app['client_phone'])) ?>" target="_blank"><?= e($app['client_phone']) ?></a></p>
                                            <?php if (is_admin()): ?><p>👩 Manicure: <?= e($app['manicure_name']) ?></p><?php endif; ?>
                                            <?php if ($app['notes']): ?><p class="italic text-slate-500 mt-1">📝 Obs: "<?= e($app['notes']) ?>"</p><?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" class="flex gap-1">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_appointment">
                                        <input type="hidden" name="id" value="<?= (int)$app['id'] ?>">
                                        <?php if ($app['status'] === 'marcado'): ?>
                                            <button name="status" value="confirmado" class="rounded bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700">Confirmar</button>
                                        <?php endif; ?>
                                        <?php if ($app['status'] !== 'concluido' && $app['status'] !== 'cancelado'): ?>
                                            <button name="status" value="concluido" class="rounded bg-blue-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-blue-700">Concluir</button>
                                            <button name="status" value="cancelado" class="rounded bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700 hover:bg-red-200">Cancelar</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h2 class="text-xl font-bold text-slate-900">Disponibilizar Horários</h2>
                    <form method="post" class="mt-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm grid gap-4">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_availability">
                        <?php if (is_admin()): ?>
                            <input type="hidden" name="manicure_id" value="<?= $selectedManicureId ?>">
                        <?php endif; ?>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-bold text-slate-700">Data Inicial *</span>
                                <input type="date" required name="available_start_date" min="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-slate-700">Data Final (Opcional)</span>
                                <input type="date" name="available_end_date" min="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm">
                            </label>
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-700 block mb-1">Selecione os Turnos *</span>
                            <div class="grid grid-cols-4 gap-1.5 max-h-36 overflow-y-auto p-1 border border-slate-200 rounded bg-slate-50">
                                <?php foreach (schedule_times() as $t): ?>
                                    <label class="flex items-center gap-1 bg-white border border-slate-200 p-1 rounded text-xs cursor-pointer select-none hover:bg-pink-50">
                                        <input type="checkbox" name="available_times[]" value="<?= e($t) ?>">
                                        <span><?= e($t) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button class="w-full rounded bg-pink-600 py-2 text-sm font-bold text-white hover:bg-pink-700">Abrir Horários na Agenda</button>
                    </form>

                    <h2 class="text-lg font-bold text-slate-900 mt-6">Minhas Datas Abertas</h2>
                    <div class="mt-3 max-h-64 overflow-y-auto space-y-1.5 border border-slate-200 rounded p-2 bg-white">
                        <?php if ($myAvailability === []): ?>
                            <p class="text-xs italic text-slate-500">Nenhum horário liberado.</p>
                        <?php else: ?>
                            <?php foreach ($myAvailability as $av): ?>
                                <div class="flex justify-between items-center bg-slate-50 border border-slate-200 px-2 py-1 rounded text-xs">
                                    <span>🗓️ <?= date('d/m/Y', strtotime($av['available_date'])) ?> às <strong><?= e($av['formatted_time']) ?></strong></span>
                                    <form method="post" onsubmit="return confirm('Remover esse horário disponível?')">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_availability">
                                        <input type="hidden" name="id" value="<?= (int)$av['id'] ?>">
                                        <button class="text-red-600 hover:text-red-800 font-bold">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($page === 'servicos'): ?>
            <?php
            $allServices = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();
            $editService = null;
            if (isset($_GET['edit'])) {
                $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$_GET['edit']]);
                $editService = $stmt->fetch() ?: null;
            }
            ?>
            <h1 class="text-3xl font-black text-slate-950">Painel de Serviços</h1>
            <p class="mt-1 text-slate-600">Cadastre novos procedimentos ou edite preços, descrições e fotos dos existentes.</p>

            <div class="mt-8 grid gap-8 lg:grid-cols-[1.2fr_.8fr]">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-xl font-bold text-slate-900 mb-4">Lista de Procedimentos</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase text-slate-500 bg-slate-50">
                                    <th class="p-3">Foto</th>
                                    <th class="p-3">Nome / Descrição</th>
                                    <th class="p-3">Preço Base</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3 text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($allServices as $s): ?>
                                    <tr>
                                        <td class="p-3">
                                            <img class="h-12 w-12 rounded object-cover border border-slate-100" src="<?= e($s['image_url'] ?: 'https://images.unsplash.com/photo-1519014816548-bf5fe059798b?auto=format&fit=crop&w=150&q=80') ?>">
                                        </td>
                                        <td class="p-3">
                                            <strong class="block text-slate-900"><?= e($s['name']) ?></strong>
                                            <span class="text-xs text-slate-500 line-clamp-1"><?= e($s['description']) ?></span>
                                        </td>
                                        <td class="p-3 font-bold text-pink-700"><?= money_br($s['price']) ?></td>
                                        <td class="p-3">
                                            <span class="rounded px-1.5 py-0.5 text-xs font-bold <?= $s['active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                                                <?= $s['active'] ? 'Ativo' : 'Inativo' ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-right space-x-1 whitespace-nowrap">
                                            <a href="?page=servicos&edit=<?= (int)$s['id'] ?>" class="text-xs font-bold text-pink-600 hover:underline">Editar</a>
                                            <form method="post" action="" class="inline" onsubmit="return confirm('Apagar permanentemente este procedimento?')">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                                <button class="text-xs font-bold text-red-600 hover:underline">Apagar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm h-fit">
                    <h2 class="text-xl font-bold text-slate-900 mb-4"><?= $editService ? 'Editar Procedimento' : 'Novo Procedimento' ?></h2>
                    <form method="post" enctype="multipart/form-data" class="grid gap-4">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_service">
                        <?php if ($editService): ?><input type="hidden" name="id" value="<?= (int)$editService['id'] ?>"><?php endif; ?>
                        
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Nome do Serviço *</span>
                            <input required name="name" value="<?= e($editService['name'] ?? '') ?>" placeholder="Ex: Alongamento em Gel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Descrição Comercial</span>
                            <textarea name="description" rows="2" placeholder="Descreva os benefícios..." class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"><?= e($editService['description'] ?? '') ?></textarea>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Preço (R$) *</span>
                                <input required name="price" value="<?= e(isset($editService['price']) ? number_format((float)$editService['price'], 2, ',', '') : '') ?>" placeholder="115,00" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Duração (min)</span>
                                <input type="number" name="duration_minutes" value="<?= (int)($editService['duration_minutes'] ?? 60) ?>" min="15" step="15" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Foto do Trabalho</span>
                            <input type="file" name="service_image" accept="image/*" class="mt-1 block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-pink-50 file:text-pink-700 file:cursor-pointer hover:file:bg-pink-100">
                            <input type="hidden" name="image_url" value="<?= e($editService['image_url'] ?? '') ?>">
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer select-none py-1">
                            <input type="checkbox" name="active" value="1" <?= !isset($editService['active']) || $editService['active'] ? 'checked' : '' ?> class="rounded text-pink-600 focus:ring-pink-500 h-4 w-4">
                            <span class="text-sm font-bold text-slate-700">Exibir este serviço no catálogo inicial</span>
                        </label>
                        
                        <div class="flex gap-2 mt-2">
                            <button class="flex-1 rounded-lg bg-pink-600 py-2.5 font-bold text-white hover:bg-pink-700">Salvar Dados</button>
                            <?php if ($editService): ?>
                                <a href="?page=servicos" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 font-bold text-slate-700 hover:bg-slate-50">Cancelar</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($page === 'usuarios'): ?>
            <?php $allUsers = $pdo->query('SELECT id, name, email, phone, role FROM users ORDER BY role, name')->fetchAll(); ?>
            <h1 class="text-3xl font-black text-slate-950">Equipe & Usuários</h1>
            <p class="mt-1 text-slate-600">Cadastre profissionais manicures ou administradores com acesso restrito ao sistema.</p>

            <div class="mt-8 grid gap-8 lg:grid-cols-[1.2fr_.8fr]">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-xl font-bold text-slate-900 mb-4">Membros da Equipe</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase text-slate-500 bg-slate-50">
                                    <th class="p-3">Nome</th>
                                    <th class="p-3">E-mail</th>
                                    <th class="p-3">Função</th>
                                    <th class="p-3 text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td class="p-3 font-bold text-slate-900"><?= e($u['name']) ?></td>
                                        <td class="p-3 text-slate-600"><?= e($u['email']) ?></td>
                                        <td class="p-3">
                                            <span class="rounded px-1.5 py-0.5 text-xs font-bold <?= $u['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-pink-100 text-pink-800' ?>">
                                                <?= e($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-right">
                                            <form method="post" onsubmit="return confirm('Remover acessos desse membro?')" class="inline">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="text-xs font-bold text-red-600 hover:underline">Remover</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm h-fit">
                    <h2 class="text-xl font-bold text-slate-900 mb-4">Novo Membro</h2>
                    <form method="post" class="grid gap-4">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_user">
                        
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Nome Completo *</span>
                            <input required name="name" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">E-mail de Acesso *</span>
                            <input type="email" required name="email" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Telefone</span>
                            <input name="phone" placeholder="(61) 99999-9999" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Senha Provisória *</span>
                            <input type="password" required name="password" minlength="6" placeholder="Mínimo 6 caracteres" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Nível de Acesso *</span>
                            <select name="role" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                                <option value="manicure">Manicure (Vê apenas sua própria agenda)</option>
                                <option value="admin">Administrador (Gerencia tudo no sistema)</option>
                            </select>
                        </label>
                        <button class="mt-2 rounded-lg bg-pink-600 py-2.5 font-bold text-white hover:bg-pink-700">Cadastrar Profissional</button>
                    </form>
                </div>
            </div>
        <?php elseif ($page === 'marketing'): ?>
            <?php
            $posts = $pdo->query('
                SELECT p.*, s.name AS service_name
                FROM marketing_posts p
                JOIN services s ON s.id = p.service_id
                ORDER BY p.created_at DESC
            ')->fetchAll();
            ?>
            <h1 class="text-3xl font-black text-slate-950">Marketing Integrado</h1>
            <p class="mt-1 text-slate-600">Gere artes promocionais automaticamente ou agende ideias de legendas para suas redes.</p>

            <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_1.1fr]">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm h-fit">
                    <h2 class="text-xl font-bold text-slate-900 mb-2">Gerador de Post Automático</h2>
                    <p class="text-xs text-slate-500 mb-4">Selecione o procedimento para montar um card pronto para download com sua marca.</p>
                    
                    <div class="grid gap-4">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">1. Escolha o Procedimento de Fundo</span>
                            <select id="mkt_service_id" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                <?php foreach ($services as $srv): ?>
                                    <option value="<?= (int)$srv['id'] ?>" data-name="<?= e($srv['name']) ?>" data-price="<?= money_br($srv['price']) ?>" data-img="<?= e($srv['image_url']) ?>">
                                        <?= e($srv['name']) ?> (<?= money_br($srv['price']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">2. Frase de Destaque no Post</span>
                            <input id="mkt_caption" value="Agende seu horário dessa semana!" placeholder="Frase curta..." class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        
                        <div>
                            <span class="text-sm font-bold text-slate-700 block mb-1">Pré-visualização da Arte</span>
                            <div class="flex justify-center border border-slate-200 rounded-lg p-2 bg-slate-100 shadow-inner">
                                <canvas id="marketing_canvas" width="600" height="600" class="max-w-full rounded shadow-md border border-white h-[320px] w-[320px]"></canvas>
                            </div>
                        </div>
                        <button id="download_mkt_art" class="rounded-lg bg-pink-600 py-2.5 font-bold text-white hover:bg-pink-700 shadow-sm transition">📥 Baixar Imagem (.PNG)</button>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-xl font-bold text-slate-900 mb-4">Planejar / Salvar Legenda</h2>
                        <form method="post" class="grid gap-4">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_marketing_post">
                            
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Procedimento Alvo</span>
                                <select required name="service_id" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                    <?php foreach ($services as $srv): ?>
                                        <option value="<?= (int)$srv['id'] ?>"><?= e($srv['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block">
                                    <span class="text-sm font-bold text-slate-700">Canal</span>
                                    <select name="channel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                        <option value="both">Instagram & WhatsApp</option>
                                        <option value="instagram">Apenas Feed/Stories</option>
                                        <option value="whatsapp_status">Status do WhatsApp</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-bold text-slate-700">Data de Publicação</span>
                                    <input type="datetime-local" name="scheduled_for" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                </label>
                            </div>
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Texto da Legenda / HashTags</span>
                                <textarea required name="caption" rows="3" placeholder="Escreva o texto..." class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
                            </label>
                            <button class="rounded-lg bg-slate-900 py-2 font-bold text-white hover:bg-slate-800 text-sm">Salvar no Cronograma</button>
                        </form>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 class="text-lg font-bold text-slate-900 mb-3">Cronograma de Posts</h2>
                        <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                            <?php if ($posts === []): ?>
                                <p class="text-xs italic text-slate-500">Nenhuma legenda salva ou planejada.</p>
                            <?php else: ?>
                                <?php foreach ($posts as $p): ?>
                                    <div class="rounded border border-slate-100 bg-slate-50 p-3 text-xs shadow-sm">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="font-bold text-slate-900 uppercase">📢 <?= e($p['service_name']) ?></span>
                                            <span class="rounded px-1 py-0.5 text-[10px] font-black uppercase <?= $p['status'] === 'publicado' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                                                <?= e($p['status']) ?>
                                            </span>
                                        </div>
                                        <p class="text-slate-700 whitespace-pre-wrap italic">"<?= e($p['caption']) ?>"</p>
                                        <?php if ($p['scheduled_for']): ?>
                                            <p class="mt-1.5 text-[10px] font-bold text-slate-500">📅 Agendado para: <?= date('d/m/Y H:i', strtotime($p['scheduled_for'])) ?></p>
                                        <?php endif; ?>
                                        <div class="mt-2 flex justify-end gap-2 border-t border-slate-200/60 pt-2">
                                            <?php if ($p['status'] !== 'publicado'): ?>
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="publish_marketing_post">
                                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                    <button class="text-emerald-700 hover:underline font-bold">Marcar Publicado</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" class="inline" onsubmit="return confirm('Remover esse post?')">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_marketing_post">
                                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                <button class="text-red-600 hover:underline">Apagar</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                const canvas = document.getElementById('marketing_canvas');
                const serviceSelect = document.getElementById('mkt_service_id');
                const captionInput = document.getElementById('mkt_caption');
                const downloadButton = document.getElementById('download_mkt_art');

                if (canvas && serviceSelect && captionInput && downloadButton) {
                    const ctx = canvas.getContext('2d');

                    function drawText(service, text) {
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';

                        ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
                        ctx.fillRect(40, 40, canvas.width - 80, canvas.height - 80);
                        ctx.strokeStyle = '#f8d8d9';
                        ctx.lineWidth = 4;
                        ctx.strokeRect(50, 50, canvas.width - 100, canvas.height - 100);

                        ctx.fillStyle = '#8f3f39';
                        ctx.font = 'bold 36px Georgia, serif';
                        ctx.fillText('Samara Eduarda', canvas.width / 2, 110);

                        ctx.fillStyle = '#de2a7a';
                        ctx.font = 'italic 20px Georgia, serif';
                        ctx.fillText('Nail Designer', canvas.width / 2, 150);

                        ctx.fillStyle = '#1c1917';
                        ctx.font = '900 42px Inter, sans-serif';
                        
                        let serviceName = service.name.toUpperCase();
                        if (serviceName.length > 20) {
                            ctx.font = '900 32px Inter, sans-serif';
                        }
                        ctx.fillText(serviceName, canvas.width / 2, 260);

                        ctx.fillStyle = '#de2a7a';
                        ctx.font = '900 56px Inter, sans-serif';
                        ctx.fillText(service.price, canvas.width / 2, 340);

                        ctx.fillStyle = '#44403c';
                        ctx.font = '500 22px Inter, sans-serif';
                        
                        const words = text.split(' ');
                        let lines = [];
                        let currentLine = '';
                        
                        for (let n = 0; n < words.length; n++) {
                            let testLine = currentLine + words[n] + ' ';
                            let metrics = ctx.measureText(testLine);
                            if (metrics.width > canvas.width - 160 && n > 0) {
                                lines.push(currentLine);
                                currentLine = words[n] + ' ';
                            } else {
                                currentLine = testLine;
                            }
                        }
                        lines.push(currentLine);

                        let startY = 440;
                        for (let i = 0; i < lines.length; i++) {
                            ctx.fillText(lines[i].trim(), canvas.width / 2, startY + (i * 30));
                        }
                    }

                    function drawFallback(service) {
                        ctx.fillStyle = '#fff1f7';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        drawText(service, captionInput.value);
                    }

                    function renderMarketingArt() {
                        const option = serviceSelect.options[serviceSelect.selectedIndex];
                        if (!option) return;

                        const service = {
                            name: option.getAttribute('data-name'),
                            price: option.getAttribute('data-price'),
                            image_url: option.getAttribute('data-img')
                        };

                        if (!service.image_url) {
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
                            drawText(service, captionInput.value);
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
                }
            </script>
        <?php endif; ?>
    </main>
</body>
</html>