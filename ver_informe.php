<?php
require_once __DIR__ . '/config/db.php';

$codigo = trim($_GET['codigo'] ?? '');
$stmt = $pdo->prepare("SELECT titulo, archivo_final, estado FROM informes WHERE codigo_unico = ?");
$stmt->execute([$codigo]);
$informe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$informe || $informe['estado'] !== 'finalizado' || empty($informe['archivo_final'])) {
    http_response_code(404);
    echo "<h2>Informe final no disponible</h2>";
    exit;
}

$rutaPdf = 'uploads/finales/' . rawurlencode($informe['archivo_final']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($informe['titulo']) ?></title>
    <style>
        body, html { margin: 0; padding: 0; height: 100%; background: #1e293b; }
        .barra { background: #0f294a; color: #fff; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; font-family: sans-serif; }
        .barra a { color: #fff; background: #1d4ed8; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 14px; }
        iframe { width: 100%; height: calc(100% - 50px); border: none; }
    </style>
</head>
<body>
    <div class="barra">
        <span><?= htmlspecialchars($informe['titulo']) ?> (<?= htmlspecialchars($codigo) ?>)</span>
        <a href="<?= $rutaPdf ?>" download>Descargar PDF</a>
    </div>
    <iframe src="<?= $rutaPdf ?>"></iframe>
</body>
</html>