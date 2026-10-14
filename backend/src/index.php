<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'reservaciones_db';
$dbUser = getenv('DB_USER') ?: 'reservaciones_user';
$dbPass = getenv('DB_PASS') ?: 'AppSecretPass2026!';

$redisHost = getenv('REDIS_HOST') ?: 'redis';
$redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
$cacheTtl  = (int)(getenv('REDIS_CACHE_TTL') ?: 45);

$smtpHost = getenv('SMTP_HOST') ?: 'mailpit';
$smtpPort = (int)(getenv('SMTP_PORT') ?: 1025);
$smtpFrom = getenv('SMTP_FROM') ?: 'notificaciones@reservaciones.local';

// Conexión a MariaDB
function getPdo($host, $port, $name, $user, $pass): PDO {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// Conexión a Redis
function getRedis($host, $port): Redis {
    $redis = new Redis();
    $redis->connect($host, $port, 2.5);
    return $redis;
}

// Función para enviar correos crudos a Mailpit (Socket SMTP)
function sendMailpitEmail($smtpHost, $smtpPort, $from, $to, $subject, $htmlBody): bool {
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 3);
    if (!$socket) return false;
    
    $read = function() use ($socket) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };
    $write = function($cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };

    $read();
    $write("EHLO backend.local"); $read();
    $write("MAIL FROM:<{$from}>"); $read();
    $write("RCPT TO:<{$to}>"); $read();
    $write("DATA"); $read();

    $headers  = "From: Sistema de Reservaciones <{$from}>\r\nTo: <{$to}>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";

    $write($headers . $htmlBody . "\r\n."); $read();
    $write("QUIT"); fclose($socket);
    return true;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/api#', '', $uri);
if ($uri === '') $uri = '/';
$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// ENDPOINT: /health (Verificar salud)
// ==========================================
if ($uri === '/health') {
    $status = ['status' => 'ok', 'mariadb' => 'down', 'redis' => 'down', 'timestamp' => date('c')];
    $httpCode = 200;
    
    try {
        getPdo($dbHost, $dbPort, $dbName, $dbUser, $dbPass)->query('SELECT 1');
        $status['mariadb'] = 'up';
    } catch (Throwable $e) {
        $status['status'] = 'error'; $status['mariadb_error'] = $e->getMessage(); $httpCode = 503;
    }

    try {
        $pong = getRedis($redisHost, $redisPort)->ping();
        $status['redis'] = ($pong === true || stripos((string)$pong, 'PONG') !== false) ? 'up' : 'down';
    } catch (Throwable $e) {
        $status['status'] = 'error'; $status['redis_error'] = $e->getMessage(); $httpCode = 503;
    }

    http_response_code($httpCode);
    echo json_encode($status);
    exit;
}

// ==========================================
// ENDPOINT: /reservaciones (CRUD)
// ==========================================
if (preg_match('#^/reservaciones(?:/(\d+))?$#', $uri, $matches)) {
    $id = isset($matches[1]) ? (int)$matches[1] : null;

    try {
        $pdo = getPdo($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        $redis = getRedis($redisHost, $redisPort);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error de conexión interna: ' . $e->getMessage()]); exit;
    }

    $cacheKey = 'reservaciones_list';
    $counterKey = 'contador_consultas';

    // GET: Listar reservaciones
    if ($method === 'GET') {
        $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
        $exportar = isset($_GET['exportar']) && $_GET['exportar'] === '1';

        // Incrementar contador en Redis
        $totalConsultas = (int)$redis->incr($counterKey);

        // Exportación JSON directa
        if ($exportar) {
            $stmt = $pdo->query("SELECT * FROM reservaciones ORDER BY fecha_reservacion ASC");
            header('Content-Disposition: attachment; filename="reservaciones_export.json"');
            echo json_encode($stmt->fetchAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
        }

        // Filtro de búsqueda (Mejora individual) ignorando caché
        if ($buscar !== '') {
            $stmt = $pdo->prepare("SELECT * FROM reservaciones WHERE espacio LIKE :q OR solicitante LIKE :q OR motivo LIKE :q ORDER BY fecha_reservacion ASC");
            $stmt->execute([':q' => "%{$buscar}%"]);
            echo json_encode([
                'origen' => 'MariaDB (Filtro)', 'contador_consultas' => $totalConsultas,
                'ttl_restante' => $redis->ttl($cacheKey), 'datos' => $stmt->fetchAll()
            ]); exit;
        }

        // Verificar Caché
        $cached = $redis->get($cacheKey);
        if ($cached !== false) {
            echo json_encode([
                'origen' => 'Redis (Caché)', 'contador_consultas' => $totalConsultas,
                'ttl_restante' => $redis->ttl($cacheKey), 'datos' => json_decode($cached, true)
            ]); exit;
        }

        // Consulta fresca y guardado en Caché
        $stmt = $pdo->query("SELECT * FROM reservaciones ORDER BY fecha_reservacion ASC, hora_inicio ASC");
        $data = $stmt->fetchAll();
        $redis->setex($cacheKey, $cacheTtl, json_encode($data));

        echo json_encode([
            'origen' => 'MariaDB (Consulta fresca)', 'contador_consultas' => $totalConsultas,
            'ttl_restante' => $cacheTtl, 'datos' => $data
        ]); exit;
    }

    // POST: Crear reservación
    if ($method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($b['espacio']) || empty($b['solicitante']) || empty($b['correo_solicitante']) || empty($b['fecha_reservacion']) || empty($b['hora_inicio']) || empty($b['hora_fin'])) {
            http_response_code(400); echo json_encode(['error' => 'Faltan campos obligatorios.']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO reservaciones (espacio, solicitante, correo_solicitante, fecha_reservacion, hora_inicio, hora_fin, prioridad, estado, motivo) VALUES (:esp, :sol, :cor, :fec, :ini, :fin, :pri, :est, :mot)");
        $stmt->execute([
            ':esp' => $b['espacio'], ':sol' => $b['solicitante'], ':cor' => $b['correo_solicitante'],
            ':fec' => $b['fecha_reservacion'], ':ini' => $b['hora_inicio'], ':fin' => $b['hora_fin'],
            ':pri' => $b['prioridad'] ?? 'Media', ':est' => $b['estado'] ?? 'Confirmada', ':mot' => $b['motivo'] ?? ''
        ]);
        
        $nuevoId = (int)$pdo->lastInsertId();
        $redis->del($cacheKey); // Invalidar caché

        // Enviar correo a Mailpit
        $html = "<h3>Reservación Confirmada #{$nuevoId}</h3><p>Espacio: {$b['espacio']}</p><p>Horario: {$b['hora_inicio']} a {$b['hora_fin']}</p>";
        $mailSent = sendMailpitEmail($smtpHost, $smtpPort, $smtpFrom, $b['correo_solicitante'], "Nueva Reservación - {$b['espacio']}", $html);

        http_response_code(201);
        echo json_encode(['mensaje' => 'Reservación creada y caché invalidada.', 'id' => $nuevoId, 'correo_enviado' => $mailSent]); exit;
    }

    // PUT: Actualizar estado
    if ($method === 'PUT' && $id !== null) {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $estado = $b['estado'] ?? null;

        $stmtCheck = $pdo->prepare("SELECT * FROM reservaciones WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $actual = $stmtCheck->fetch();

        if (!$actual) { http_response_code(404); echo json_encode(['error' => 'No encontrada.']); exit; }

        if ($estado) {
            $pdo->prepare("UPDATE reservaciones SET estado = :est WHERE id = :id")->execute([':est' => $estado, ':id' => $id]);
            $redis->del($cacheKey);
            
            // Segundo correo de aviso si se cancela
            if ($estado === 'Cancelada' && $actual['estado'] !== 'Cancelada') {
                sendMailpitEmail($smtpHost, $smtpPort, $smtpFrom, $actual['correo_solicitante'], "Cancelación - {$actual['espacio']}", "<p>La reservación fue Cancelada.</p>");
            }
        }
        echo json_encode(['mensaje' => 'Actualizada correctamente.']); exit;
    }

    // DELETE: Eliminar
    if ($method === 'DELETE' && $id !== null) {
        $stmtCheck = $pdo->prepare("SELECT * FROM reservaciones WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $actual = $stmtCheck->fetch();
        if ($actual) {
            $pdo->prepare("DELETE FROM reservaciones WHERE id = :id")->execute([':id' => $id]);
            $redis->del($cacheKey);
            sendMailpitEmail($smtpHost, $smtpPort, $smtpFrom, $actual['correo_solicitante'], "Eliminada - {$actual['espacio']}", "<p>Registro eliminado.</p>");
        }
        echo json_encode(['mensaje' => 'Eliminada correctamente.']); exit;
    }
}

http_response_code(404); echo json_encode(['error' => 'Ruta no encontrada']);