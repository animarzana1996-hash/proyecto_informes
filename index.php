
<?php

require_once __DIR__ . '/config/python_bin.php';

$error = '';

// =============================================
// LIMPIAR ARCHIVOS TEMPORALES
// =============================================

function limpiarTemporales(array $archivos): void
{
    foreach ($archivos as $ruta) {
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }
}

// =============================================
// VALIDAR LISTA DE PAGINAS
// =============================================

function validarLista($valor): string
{
    $valor = trim((string)$valor);

    if (
        $valor !== '' &&
        !preg_match('/^\d+(\s*,\s*\d+)*$/', $valor)
    ) {
        throw new RuntimeException(
            'Lista de paginas invalida.'
        );
    }

    return $valor;
}

// =============================================
// PROCESAR FORMULARIO
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $temporales = [];

    try {

        // VALIDAR LOS DOS PDF

        $campos = [
            'pdf_informe',
            'pdf_imagenes'
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach ($campos as $campo) {

            if (
                !isset($_FILES[$campo]) ||
                is_array($_FILES[$campo]['error']) ||
                $_FILES[$campo]['error'] !== UPLOAD_ERR_OK ||
                !is_uploaded_file($_FILES[$campo]['tmp_name'])
            ) {
                throw new RuntimeException(
                    'Debes subir ambos PDF correctamente.'
                );
            }

            if (
                $finfo->file($_FILES[$campo]['tmp_name'])
                !== 'application/pdf'
            ) {
                throw new RuntimeException(
                    'Los dos archivos deben ser PDF validos.'
                );
            }
        }

        // RECIBIR PAGINAS Y ORDEN

        $quitar1 = validarLista(
            $_POST['eliminar_informe'] ?? ''
        );

        $quitar2 = validarLista(
            $_POST['eliminar_imagenes'] ?? ''
        );

        $orden1 = validarLista(
            $_POST['orden_informe'] ?? ''
        );

        $orden2 = validarLista(
            $_POST['orden_imagenes'] ?? ''
        );

        // CREAR ARCHIVOS TEMPORALES

        $id = bin2hex(random_bytes(12));

        $base = rtrim(
            sys_get_temp_dir(),
            '\\/'
        ) . DIRECTORY_SEPARATOR . 'cardio_' . $id;

        $pdfInforme = $base . '_informe.pdf';
        $pdfImagenes = $base . '_imagenes.pdf';
        $pdfSalida = $base . '_unificado.pdf';

        $temporales = [
            $pdfInforme,
            $pdfImagenes,
            $pdfSalida
        ];

        // GUARDAR PDF ORIGINALES

        if (
            !move_uploaded_file(
                $_FILES['pdf_informe']['tmp_name'],
                $pdfInforme
            ) ||
            !move_uploaded_file(
                $_FILES['pdf_imagenes']['tmp_name'],
                $pdfImagenes
            )
        ) {
            throw new RuntimeException(
                'No se pudieron guardar los archivos temporales.'
            );
        }

        // EJECUTAR PYTHON

        $script = __DIR__ .
            '/python/unir_pdf_renumerar.py';

        if (!is_file($script)) {
            throw new RuntimeException(
                'Falta python/unir_pdf_renumerar.py.'
            );
        }

        $python = resolverBinarioPython();

        $args = [
            $python,
            $script,
            $pdfInforme,
            $pdfImagenes,
            $pdfSalida,
            $quitar1,
            $quitar2,
            $orden1,
            $orden2
        ];

        $comando = implode(
            ' ',
            array_map('escapeshellarg', $args)
        ) . ' 2>&1';

        $salida = [];
        $estado = -1;

        exec($comando, $salida, $estado);

        // VERIFICAR RESULTADO

        if (
            $estado !== 0 ||
            !is_file($pdfSalida) ||
            filesize($pdfSalida) === 0
        ) {

            error_log(
                'Error uniendo PDF: ' .
                implode(PHP_EOL, $salida)
            );

            throw new RuntimeException(
                'No se pudo generar el PDF. ' .
                'Consulta el registro de Apache.'
            );
        }

        // DESCARGAR PDF

        header('Content-Type: application/pdf');

        header(
            'Content-Disposition: attachment; filename="' .
            'Informe_Cardiologia_' .
            date('Ymd_His') .
            '.pdf"'
        );

        header(
            'Content-Length: ' .
            filesize($pdfSalida)
        );

        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        readfile($pdfSalida);

        limpiarTemporales($temporales);

        exit;

    } catch (Throwable $e) {

        error_log(
            'Error index.php: ' .
            $e->getMessage()
        );

        $error = $e->getMessage();

        limpiarTemporales($temporales);
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Editor visual de informes PDF</title>

<style>

:root {
    font-family: Arial, Helvetica, sans-serif;
    color: #172b45;
    background: #f3f6fa;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
}

header {
    background: #0f294a;
    color: white;
    padding: 18px 24px;
    font-size: 20px;
    font-weight: 700;
}

main {
    max-width: 1170px;
    margin: 25px auto;
    padding: 0 18px 40px;
}

h1 {
    font-size: 25px;
    margin: 0 0 8px;
}

p {
    line-height: 1.5;
    color: #53657a;
}

.panel {
    background: white;
    border: 1px solid #d5dfeb;
    border-radius: 12px;
    margin: 18px 0;
    overflow: hidden;
    box-shadow: 0 3px 12px #112c4a09;
}

.panel-header {
    background: #0f294a;
    color: white;
    padding: 14px 18px;
    font-weight: bold;
}

.panel-body {
    padding: 18px;
}

.help {
    font-size: 14px;
    margin: 8px 0 14px;
    color: #607086;
}

input[type=file] {
    width: 100%;
    max-width: 600px;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: white;
}

/* GALERIA DE PAGINAS */

.gallery {
    display: grid;
    grid-template-columns:
        repeat(auto-fill, minmax(146px, 1fr));
    gap: 13px;
    margin-top: 17px;
}

.tile {
    border: 2px solid #d6dfeb;
    border-radius: 9px;
    background: #f8fafc;
    padding: 8px;
    position: relative;
    min-width: 0;
}

/* PAGINA MARCADA PARA ELIMINAR */

.tile.removed {
    border: 2px dashed #e06767;
    background: #fff1f1;
}

.thumb {
    position: relative;
    min-height: 165px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 5px;
    overflow: hidden;
    cursor: pointer;
}

.thumb canvas {
    max-width: 100%;
    max-height: 210px;
    object-fit: contain;
    display: block;
}

.tile.removed .thumb canvas {
    opacity: 0.28;
    filter: grayscale(1);
}

/* X ROJA */

.cross {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    color: #d62828;
    font-size: 64px;
    font-weight: bold;
    pointer-events: none;
}

.tile.removed .cross {
    display: flex;
}

.label-page {
    font-size: 13px;
    text-align: center;
    margin: 7px 0;
    font-weight: bold;
}

.badge-state {
    font-size: 11px;
    color: #047857;
    text-align: center;
    min-height: 16px;
}

.tile.removed .badge-state {
    color: #a52222;
}

.tile-actions {
    display: flex;
    gap: 5px;
    justify-content: space-around;
    margin-top: 8px;
}

.smallbtn {
    border: 1px solid #bbc9d8;
    background: white;
    border-radius: 6px;
    padding: 6px;
    cursor: pointer;
    font-size: 13px;
    min-width: 32px;
}

.smallbtn:disabled {
    opacity: 0.3;
    cursor: default;
}

.togglebtn {
    flex: 1;
    color: #0f294a;
    font-weight: bold;
}

.tile.removed .togglebtn {
    color: #b42332;
}

/* MENSAJES */

.notice {
    background: #e9f1fb;
    border-left: 4px solid #0f294a;
    padding: 14px;
    border-radius: 6px;
    font-size: 14px;
}

.danger {
    background: #fde8e8;
    color: #8f1d27;
    padding: 13px;
    border-radius: 7px;
    margin-bottom: 14px;
}

.summary {
    margin: 18px 0;
    color: #263c59;
    font-weight: 600;
}

.primary {
    background: #0f294a;
    color: white;
    width: 100%;
    border: none;
    border-radius: 8px;
    padding: 15px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

.primary:disabled {
    background: #7e91a8;
    cursor: not-allowed;
}

#estadoCarga {
    font-size: 13px;
    color: #42617a;
    min-height: 17px;
    margin-top: 10px;
}

</style>

<!-- BIBLIOTECA PARA MOSTRAR MINIATURAS PDF -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

</head>

<body>

<header>
    Sistema de Informes Cardiológicos
</header>

<main>

<h1>Unir, ordenar y eliminar hojas del PDF</h1>

<p>
    Seleccioná los archivos y tocá cada miniatura
    para marcarla con una X roja.
    También podés cambiar el orden con las flechas.
    Las páginas de imágenes se renumerarán desde 1.
</p>

<?php if ($error !== ''): ?>

    <div class="danger">
        <?= htmlspecialchars(
            $error,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>

<?php endif; ?>

<form
    id="editor"
    method="POST"
    enctype="multipart/form-data"
>

    <!-- PRIMER PDF -->

    <section class="panel">

        <div class="panel-header">
            1. PDF del informe médico
        </div>

        <div class="panel-body">

            <input
                id="archivoInforme"
                type="file"
                name="pdf_informe"
                accept=".pdf,application/pdf"
                required
            >

            <div class="help">
                Estas páginas no se renumeran.
                Tocá una hoja para eliminarla
                o recuperarla.
            </div>

            <div
                id="paginasInforme"
                class="gallery"
            ></div>

        </div>

    </section>

    <!-- SEGUNDO PDF -->

    <section class="panel">

        <div class="panel-header">
            2. PDF de imágenes de Cardiología
        </div>

        <div class="panel-body">

            <input
                id="archivoImagenes"
                type="file"
                name="pdf_imagenes"
                accept=".pdf,application/pdf"
                required
            >

            <div class="help">
                No se elimina la primera hoja
                automáticamente.

                Elegí las hojas que quieras quitar.

                Las conservadas se numerarán:
                Cardiología - 1/N, 2/N, 3/N...
            </div>

            <div
                id="paginasImagenes"
                class="gallery"
            ></div>

        </div>

    </section>

    <!-- DATOS PARA PYTHON -->

    <input
        type="hidden"
        name="eliminar_informe"
        id="quitarInforme"
    >

    <input
        type="hidden"
        name="eliminar_imagenes"
        id="quitarImagenes"
    >

    <input
        type="hidden"
        name="orden_informe"
        id="ordenInforme"
    >

    <input
        type="hidden"
        name="orden_imagenes"
        id="ordenImagenes"
    >

    <div class="notice">

        Las miniaturas se generan en el navegador.

        Se unen los PDF originales sin convertir
        las páginas en imágenes.

        Solo se actualiza la numeración
        de Cardiología.

    </div>

    <div
        id="resumen"
        class="summary"
    >
        Seleccioná los dos PDF para comenzar.
    </div>

    <button
        id="generar"
        type="submit"
        class="primary"
        disabled
    >
        Unir y descargar PDF final
    </button>

    <div
        id="estadoCarga"
        role="status"
        aria-live="polite"
    ></div>

</form>

</main>

<script>

(() => {

'use strict';

// ==========================================
// ESTADO DE LAS PAGINAS
// ==========================================

const estado = {

    informe: {
        pages: [],
        ready: false
    },

    imagenes: {
        pages: [],
        ready: false
    }

};

const ids = {

    informe: {
        input: 'archivoInforme',
        gallery: 'paginasInforme',
        del: 'quitarInforme',
        order: 'ordenInforme'
    },

    imagenes: {
        input: 'archivoImagenes',
        gallery: 'paginasImagenes',
        del: 'quitarImagenes',
        order: 'ordenImagenes'
    }

};

const boton = document.getElementById('generar');

const mensaje = document.getElementById('estadoCarga');

const resumen = document.getElementById('resumen');

let enviando = false;

// ==========================================
// CONFIGURAR PDF.JS
// ==========================================

if (window.pdfjsLib) {

    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

} else {

    mensaje.textContent =
        'No se pudo cargar PDF.js. Verifica Internet.';

}

// ==========================================
// ACTUALIZAR DATOS
// ==========================================

function actualizar() {

    for (const tipo of ['informe', 'imagenes']) {

        const paginas = estado[tipo].pages;

        const eliminadas = paginas
            .filter(p => p.removed)
            .map(p => p.num);

        const orden = paginas.map(p => p.num);

        document.getElementById(
            ids[tipo].del
        ).value = eliminadas.join(',');

        document.getElementById(
            ids[tipo].order
        ).value = orden.join(',');

    }

    const a = estado.informe.pages
        .filter(p => !p.removed).length;

    const b = estado.imagenes.pages
        .filter(p => !p.removed).length;

    resumen.textContent =
        `Informe: ${a} hojas conservadas · ` +
        `Imágenes: ${b} hojas conservadas · ` +
        `PDF final: ${a + b} páginas.`;

    boton.disabled =
        enviando ||
        !estado.informe.ready ||
        !estado.imagenes.ready ||
        a + b === 0;

}

// ==========================================
// MOSTRAR MINIATURAS
// ==========================================

function pintar(tipo) {

    const galeria = document.getElementById(
        ids[tipo].gallery
    );

    galeria.replaceChildren();

    estado[tipo].pages.forEach((p, index) => {

        // TARJETA DE PAGINA

        const tile = document.createElement('div');

        tile.className =
            'tile' + (p.removed ? ' removed' : '');

        // MINIATURA

        const thumb = document.createElement('div');

        thumb.className = 'thumb';

        thumb.title = p.removed
            ? 'Recuperar esta hoja'
            : 'Marcar esta hoja para eliminar';

        const canvas = document.createElement('canvas');

        canvas.width = p.canvas.width;
        canvas.height = p.canvas.height;

        canvas.getContext('2d').drawImage(
            p.canvas,
            0,
            0
        );

        thumb.appendChild(canvas);

        // X ROJA

        const cross = document.createElement('span');

        cross.className = 'cross';
        cross.textContent = '×';

        thumb.appendChild(cross);

        thumb.addEventListener('click', () => {

            p.removed = !p.removed;

            pintar(tipo);
            actualizar();

        });

        // NUMERO DE PAGINA

        const label = document.createElement('div');

        label.className = 'label-page';

        label.textContent = 'Página ' + p.num;

        // ESTADO

        const state = document.createElement('div');

        state.className = 'badge-state';

        state.textContent = p.removed
            ? 'Se eliminará'
            : 'Se conserva';

        // BOTONES

        const actions = document.createElement('div');

        actions.className = 'tile-actions';

        // MOVER A LA IZQUIERDA

        const prev = document.createElement('button');

        prev.type = 'button';
        prev.className = 'smallbtn';
        prev.textContent = '←';
        prev.title = 'Mover a la izquierda';

        prev.disabled = index === 0;

        prev.addEventListener('click', () => {

            [
                estado[tipo].pages[index - 1],
                estado[tipo].pages[index]
            ] = [
                p,
                estado[tipo].pages[index - 1]
            ];

            pintar(tipo);
            actualizar();

        });

        // ELIMINAR O RECUPERAR

        const toggle = document.createElement('button');

        toggle.type = 'button';

        toggle.className =
            'smallbtn togglebtn';

        toggle.textContent = p.removed
            ? 'Recuperar'
            : 'Eliminar';

        toggle.addEventListener('click', () => {

            p.removed = !p.removed;

            pintar(tipo);
            actualizar();

        });

        // MOVER A LA DERECHA

        const next = document.createElement('button');

        next.type = 'button';
        next.className = 'smallbtn';
        next.textContent = '→';
        next.title = 'Mover a la derecha';

        next.disabled =
            index === estado[tipo].pages.length - 1;

        next.addEventListener('click', () => {

            [
                estado[tipo].pages[index],
                estado[tipo].pages[index + 1]
            ] = [
                estado[tipo].pages[index + 1],
                p
            ];

            pintar(tipo);
            actualizar();

        });

        actions.append(
            prev,
            toggle,
            next
        );

        tile.append(
            thumb,
            label,
            state,
            actions
        );

        galeria.appendChild(tile);

    });

}

// ==========================================
// PREVISUALIZAR PDF
// ==========================================

async function previsualizar(tipo) {

    const file = document.getElementById(
        ids[tipo].input
    ).files[0];

    estado[tipo] = {
        pages: [],
        ready: false
    };

    document.getElementById(
        ids[tipo].gallery
    ).replaceChildren();

    actualizar();

    if (!file || !window.pdfjsLib) {
        return;
    }

    mensaje.textContent =
        `Preparando miniaturas de ${tipo}...`;

    try {

        const buffer = await file.arrayBuffer();

        const pdf = await pdfjsLib.getDocument({
            data: new Uint8Array(buffer)
        }).promise;

        for (
            let i = 1;
            i <= pdf.numPages;
            i++
        ) {

            const page = await pdf.getPage(i);

            const vp = page.getViewport({
                scale: 1
            });

            const scale = Math.min(
                1,
                175 / vp.width,
                220 / vp.height
            );

            const viewport = page.getViewport({
                scale
            });

            const canvas = document.createElement(
                'canvas'
            );

            canvas.width = Math.max(
                1,
                Math.round(viewport.width)
            );

            canvas.height = Math.max(
                1,
                Math.round(viewport.height)
            );

            await page.render({

                canvasContext:
                    canvas.getContext('2d'),

                viewport

            }).promise;

            estado[tipo].pages.push({

                num: i,
                removed: false,
                canvas

            });

        }

        await pdf.destroy();

        estado[tipo].ready = true;

        pintar(tipo);

        mensaje.textContent =
            'Miniaturas listas. ' +
            'Marca las hojas con una X.';

    } catch (e) {

        console.error(e);

        mensaje.textContent =
            'No se pudo previsualizar el PDF: ' +
            e.message;

    }

    actualizar();

}

// ==========================================
// EVENTOS
// ==========================================

document.getElementById('archivoInforme')
    .addEventListener('change', () => {

        previsualizar('informe');

    });

document.getElementById('archivoImagenes')
    .addEventListener('change', () => {

        previsualizar('imagenes');

    });

document.getElementById('editor')
    .addEventListener('submit', e => {

        actualizar();

        if (boton.disabled) {

            e.preventDefault();
            return;

        }

        enviando = true;

        boton.disabled = true;

        boton.textContent =
            'Generando PDF...';

        // Restablecer el boton tras iniciar
        // la descarga del archivo.

        window.setTimeout(() => {

            enviando = false;

            boton.textContent =
                'Unir y descargar PDF final';

            actualizar();

        }, 12000);

    });

// INICIALIZAR

actualizar();

})();

</script>

</body>
</html>

<