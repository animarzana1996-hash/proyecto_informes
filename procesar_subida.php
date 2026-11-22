
<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/python_bin.php';

// ==========================================
// RESPUESTA JSON
// ==========================================

function responder($ok, $datos = [], $codigoHttp = 200)
{
    http_response_code($codigoHttp);

    echo json_encode(
        array_merge(['ok' => $ok], $datos),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// ==========================================
// 1. VALIDAR METODO POST
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, [
        'error' => 'Metodo no permitido.'
    ], 405);
}

// ==========================================
// 2. VALIDAR ARCHIVOS PDF
// ==========================================

$campos = ['pdf_informe', 'pdf_imagenes'];

foreach ($campos as $campo) {

    if (!isset($_FILES[$campo])) {
        responder(false, [
            'error' => 'Debes seleccionar ambos archivos PDF.'
        ], 400);
    }

    if (
        $_FILES[$campo]['error'] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES[$campo]['tmp_name'])
    ) {
        responder(false, [
            'error' => 'Error al subir el archivo: ' . $campo
        ], 400);
    }
}

// ==========================================
// 3. COMPROBAR FORMATO PDF
// ==========================================

$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($campos as $campo) {

    $tipo = $finfo->file($_FILES[$campo]['tmp_name']);

    if ($tipo !== 'application/pdf') {
        responder(false, [
            'error' => 'El archivo ' . $campo .
                       ' debe ser un PDF valido.'
        ], 400);
    }
}

// ==========================================
// 4. OBTENER TITULO DEL INFORME
// ==========================================

$titulo = trim($_POST['titulo'] ?? 'Informe');

if ($titulo === '') {
    $titulo = 'Informe';
}

// ==========================================
// 5. CREAR CARPETAS
// ==========================================

$dirTemp = __DIR__ . '/uploads/temp/';
$dirEditables = __DIR__ . '/uploads/editables/';

foreach ([$dirTemp, $dirEditables] as $directorio) {

    if (
        !is_dir($directorio) &&
        !mkdir($directorio, 0775, true) &&
        !is_dir($directorio)
    ) {
        responder(false, [
            'error' => 'No se pudo crear el directorio: ' .
                       basename($directorio)
        ], 500);
    }
}

// ==========================================
// 6. GENERAR CODIGO UNICO
// ==========================================

try {

    $codigoUnico = 'INF-' .
        date('Ymd') . '-' .
        strtoupper(bin2hex(random_bytes(6)));

} catch (Throwable $e) {

    responder(false, [
        'error' => 'No se pudo generar el codigo del informe.'
    ], 500);
}

// ==========================================
// 7. DEFINIR RUTAS
// ==========================================

$pdfTextoTmp = $dirTemp . $codigoUnico . '_texto.pdf';

$pdfImgsTmp = $dirTemp . $codigoUnico . '_imgs.pdf';

$nombreDocx = 'Editable_' . $codigoUnico . '.docx';

$rutaDocx = $dirEditables . $nombreDocx;

$scriptPython = __DIR__ . '/python/armar_informe.py';

// La plantilla es OPCIONAL.
// Si no existe, Python crea un Word nuevo.
$plantilla = __DIR__ . '/plantilla_informe.docx';

if (!is_file($plantilla)) {
    $plantilla = '';
}

// ==========================================
// 8. COMPROBAR SCRIPT PYTHON
// ==========================================

if (!is_file($scriptPython)) {

    responder(false, [
        'error' => 'No existe el archivo python/armar_informe.py.'
    ], 500);
}

// ==========================================
// 9. GUARDAR PDF TEMPORALES
// ==========================================

if (!move_uploaded_file(
    $_FILES['pdf_informe']['tmp_name'],
    $pdfTextoTmp
)) {

    responder(false, [
        'error' => 'No se pudo guardar el PDF del informe.'
    ], 500);
}

if (!move_uploaded_file(
    $_FILES['pdf_imagenes']['tmp_name'],
    $pdfImgsTmp
)) {

    @unlink($pdfTextoTmp);

    responder(false, [
        'error' => 'No se pudo guardar el PDF de imagenes.'
    ], 500);
}

// ==========================================
// 10. EJECUTAR PYTHON Y GENERAR WORD
// ==========================================

try {

    $binPython = resolverBinarioPython();

    if (
        !is_string($binPython) ||
        trim($binPython) === ''
    ) {
        throw new RuntimeException(
            'No se encontro el ejecutable de Python.'
        );
    }

    // Pasar los argumentos esperados por armar_informe.py

    $comando = sprintf(
        '%s %s %s %s %s %s 2>&1',
        escapeshellarg($binPython),
        escapeshellarg($scriptPython),
        escapeshellarg($pdfTextoTmp),
        escapeshellarg($pdfImgsTmp),
        escapeshellarg($rutaDocx),
        escapeshellarg($plantilla)
    );

    $salida = [];
    $codigoSalida = -1;

    exec($comando, $salida, $codigoSalida);

    $mensajePython = implode(PHP_EOL, $salida);

    // ==========================================
    // 11. VALIDAR WORD GENERADO
    // ==========================================

    if (
        $codigoSalida !== 0 ||
        !is_file($rutaDocx) ||
        filesize($rutaDocx) === 0
    ) {

        error_log(
            'Error Python [' . $codigoUnico . ']: ' .
            $mensajePython
        );

        @unlink($rutaDocx);

        responder(false, [
            'error' => 'No se pudo generar el documento Word. ' .
                       'Revisa el registro de errores de PHP.'
        ], 500);
    }

    // ==========================================
    // 12. GUARDAR EN BASE DE DATOS
    // ==========================================

    $stmt = $pdo->prepare("
        INSERT INTO informes
        (
            codigo_unico,
            titulo,
            archivo_editable,
            estado
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'editable'
        )
    ");

    $stmt->execute([
        $codigoUnico,
        $titulo,
        $nombreDocx
    ]);

    // ==========================================
    // 13. RESPUESTA EXITOSA
    // ==========================================

    responder(true, [
        'codigo_unico' => $codigoUnico,
        'titulo' => $titulo,
        'docx_url' =>
            'uploads/editables/' .
            rawurlencode($nombreDocx)
    ]);

} catch (Throwable $e) {

    error_log(
        'Error procesar_subida.php [' .
        $codigoUnico . ']: ' .
        $e->getMessage()
    );

    @unlink($rutaDocx);

    responder(false, [
        'error' => 'Ocurrio un error durante el procesamiento.'
    ], 500);

} finally {

    // ==========================================
    // 14. ELIMINAR ARCHIVOS TEMPORALES
    // ==========================================

    if (is_file($pdfTextoTmp)) {
        @unlink($pdfTextoTmp);
    }

    if (is_file($pdfImgsTmp)) {
        @unlink($pdfImgsTmp);
    }
}
