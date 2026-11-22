<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/python_bin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

$codigo = trim($_POST['codigo_unico'] ?? '');
if ($codigo === '' || empty($_FILES['pdf_final']['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'Falta el codigo del informe o el PDF final.']);
    exit;
}

if ($_FILES['pdf_final']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Error al subir el PDF final.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM informes WHERE codigo_unico = ?");
$stmt->execute([$codigo]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'El codigo de informe no existe en la base de datos.']);
    exit;
}

$dirFinales = __DIR__ . '/uploads/finales/';
$dirQrPdfs  = __DIR__ . '/uploads/qr_pdfs/';
if (!is_dir($dirFinales)) mkdir($dirFinales, 0775, true);
if (!is_dir($dirQrPdfs))  mkdir($dirQrPdfs, 0775, true);

$nombrePdfFinal = "Informe_Final_" . $codigo . ".pdf";
$rutaPdfFinal   = $dirFinales . $nombrePdfFinal;

if (!move_uploaded_file($_FILES['pdf_final']['tmp_name'], $rutaPdfFinal)) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el PDF final.']);
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "http://" . $host . dirname($_SERVER['SCRIPT_NAME']);
$urlInformeFinal = rtrim($baseUrl, '/') . "/ver_informe.php?codigo=" . urlencode($codigo);

$nombrePdfQr = "QR_" . $codigo . ".pdf";
$rutaPdfQr   = $dirQrPdfs . $nombrePdfQr;

$binPython = resolverBinarioPython();

$cmd = sprintf(
    '%s %s %s %s %s 2>&1',
    escapeshellarg($binPython),
    escapeshellarg(__DIR__ . '/python/crear_pdf_qr.py'),
    escapeshellarg($urlInformeFinal),
    escapeshellarg($codigo),
    escapeshellarg($rutaPdfQr)
);
$salidaPython = shell_exec($cmd);

if (!file_exists($rutaPdfQr)) {
    echo json_encode(['ok' => false, 'error' => 'Error al generar el PDF del QR: ' . trim((string)$salidaPython)]);
    exit;
}

$upd = $pdo->prepare("
    UPDATE informes
    SET archivo_final = ?, pdf_qr = ?, estado = 'finalizado', finalizado_en = NOW()
    WHERE codigo_unico = ?
");
$upd->execute([$nombrePdfFinal, $nombrePdfQr, $codigo]);

echo json_encode([
    'ok'              => true,
    'url_ver_informe' => $urlInformeFinal,
    'url_pdf_qr'      => 'uploads/qr_pdfs/' . rawurlencode($nombrePdfQr)
]);