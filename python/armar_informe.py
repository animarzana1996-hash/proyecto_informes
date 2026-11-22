
import sys
import os
import re
import tempfile
import traceback

import fitz

from docx import Document
from docx.shared import Inches, Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.text import WD_BREAK


# ==========================================
# 1. MODIFICAR NUMERACION DE CARDIOLOGIA
# ==========================================

def actualizar_numero_pagina(pagina, numero, total):

    nuevo_texto = (
        f"Cardiología - {numero}/{total} Page"
    )

    patron = re.compile(
        r"Cardiolog[ií]a\s*-\s*\d+\s*/\s*\d+\s*Page",
        re.IGNORECASE
    )

    coincidencias = []

    datos = pagina.get_text("dict")

    for bloque in datos["blocks"]:

        if "lines" not in bloque:
            continue

        for linea in bloque["lines"]:

            spans = linea["spans"]

            texto = "".join(
                span["text"] for span in spans
            )

            if patron.search(texto):
                coincidencias.append(linea)

    if len(coincidencias) != 1:

        raise RuntimeError(
            "No se encontro una numeracion unica "
            f"de Cardiologia en la pagina {numero}."
        )

    linea = coincidencias[0]

    spans = linea["spans"]

    rect_original = fitz.Rect(
        linea["bbox"]
    )

    fuente = max(
        span["size"] for span in spans
    )

    # Borrar unicamente el texto original.
    # Se conservan las imagenes y los bordes.
    rect_borrado = fitz.Rect(
        rect_original.x0 - 2,
        rect_original.y0 - 1,
        rect_original.x1 + 2,
        rect_original.y1 + 1
    )

    pagina.add_redact_annot(
        rect_borrado,
        fill=(1, 1, 1)
    )

    pagina.apply_redactions(
        images=0,
        graphics=0,
        text=0
    )

    # Ubicar el nuevo texto centrado.
    centro_x = (
        rect_original.x0 + rect_original.x1
    ) / 2

    origen_y = spans[0]["origin"][1]

    # Reducir la fuente si fuera necesario.
    ancho_disponible = rect_original.width + 10

    while (
        fitz.get_text_length(
            nuevo_texto,
            fontname="helv",
            fontsize=fuente
        ) > ancho_disponible
        and fuente > 6
    ):
        fuente -= 0.3

    ancho_texto = fitz.get_text_length(
        nuevo_texto,
        fontname="helv",
        fontsize=fuente
    )

    inicio_x = centro_x - ancho_texto / 2

    pagina.insert_text(
        (inicio_x, origen_y),
        nuevo_texto,
        fontsize=fuente,
        fontname="helv",
        color=(0, 0, 0)
    )

    print(
        f"Numeracion actualizada: {nuevo_texto}"
    )


# ==========================================
# 2. CONVERTIR PAGINA PDF A IMAGEN
# ==========================================

def convertir_pagina_a_imagen(pagina, ruta):

    matriz = fitz.Matrix(2, 2)

    pix = pagina.get_pixmap(
        matrix=matriz,
        alpha=False
    )

    pix.save(ruta)

    return pix.width, pix.height


# ==========================================
# 3. GENERAR WORD UNIFICADO
# ==========================================

def generar_word_editable(
    pdf_texto_path,
    pdf_img_path,
    salida_docx,
    plantilla_path=""
):

    if not os.path.isfile(pdf_texto_path):
        raise FileNotFoundError(
            "No existe el primer PDF."
        )

    if not os.path.isfile(pdf_img_path):
        raise FileNotFoundError(
            "No existe el segundo PDF."
        )

    carpeta_salida = os.path.dirname(
        os.path.abspath(salida_docx)
    )

    os.makedirs(
        carpeta_salida,
        exist_ok=True
    )

    with tempfile.TemporaryDirectory() as temp:

        imagenes = []

        # ==================================
        # PRIMER PDF: CONSERVAR TODO
        # ==================================

        print("Procesando primer PDF...")

        with fitz.open(pdf_texto_path) as pdf1:

            for i in range(len(pdf1)):

                pagina = pdf1[i]

                ruta = os.path.join(
                    temp,
                    f"informe_{i + 1}.png"
                )

                ancho, alto = convertir_pagina_a_imagen(
                    pagina,
                    ruta
                )

                imagenes.append(
                    (ruta, ancho, alto)
                )

        # ==================================
        # SEGUNDO PDF: ELIMINAR PRIMERA HOJA
        # ==================================

        print("Procesando PDF de imagenes...")

        with fitz.open(pdf_img_path) as pdf2:

            total = max(0, len(pdf2) - 1)

            if total == 0:
                print(
                    "El segundo PDF no tiene "
                    "paginas adicionales."
                )

            for i in range(1, len(pdf2)):

                pagina = pdf2[i]

                numero_nuevo = i

                # Cambiar 2/4 por 1/3, etc.
                actualizar_numero_pagina(
                    pagina,
                    numero_nuevo,
                    total
                )

                ruta = os.path.join(
                    temp,
                    f"cardiologia_{numero_nuevo}.png"
                )

                ancho, alto = convertir_pagina_a_imagen(
                    pagina,
                    ruta
                )

                imagenes.append(
                    (ruta, ancho, alto)
                )

        # ==================================
        # CREAR DOCUMENTO WORD
        # ==================================

        print("Generando Word...")

        documento = Document()

        seccion = documento.sections[0]

        # Tamaño A4
        seccion.page_width = Inches(8.27)
        seccion.page_height = Inches(11.69)

        # Margenes pequeños
        seccion.top_margin = Inches(0.15)
        seccion.bottom_margin = Inches(0.15)
        seccion.left_margin = Inches(0.15)
        seccion.right_margin = Inches(0.15)

        ancho_util = (
            seccion.page_width
            - seccion.left_margin
            - seccion.right_margin
        )

        alto_util = (
            seccion.page_height
            - seccion.top_margin
            - seccion.bottom_margin
        )

        # Dejar un pequeño espacio de seguridad
        alto_util = alto_util - Inches(0.12)

        estilo = documento.styles["Normal"]

        estilo.paragraph_format.space_before = Pt(0)
        estilo.paragraph_format.space_after = Pt(0)

        for indice, (ruta, ancho, alto) in enumerate(imagenes):

            parrafo = documento.add_paragraph()

            # Cada imagen empieza en una hoja nueva
            # salvo la primera.
            if indice > 0:
                parrafo.paragraph_format.page_break_before = True

            parrafo.alignment = (
                WD_ALIGN_PARAGRAPH.CENTER
            )

            parrafo.paragraph_format.space_before = Pt(0)
            parrafo.paragraph_format.space_after = Pt(0)
            parrafo.paragraph_format.line_spacing = 1

            escala = min(
                ancho_util / ancho,
                alto_util / alto
            )

            ancho_final = int(ancho * escala)
            alto_final = int(alto * escala)

            run = parrafo.add_run()

            run.add_picture(
                ruta,
                width=ancho_final,
                height=alto_final
            )

        # ==================================
        # GUARDAR WORD
        # ==================================

        documento.save(salida_docx)

        print(
            "Word generado correctamente:",
            salida_docx
        )

        print(
            "Total de paginas incorporadas:",
            len(imagenes)
        )


# ==========================================
# 4. EJECUCION DESDE PHP
# ==========================================

if __name__ == "__main__":

    if len(sys.argv) < 4:

        print(
            "Uso: python armar_informe.py "
            "pdf1 pdf2 salida.docx [plantilla]"
        )

        sys.exit(1)

    try:

        generar_word_editable(
            sys.argv[1],
            sys.argv[2],
            sys.argv[3],
            sys.argv[4] if len(sys.argv) > 4 else ""
        )

    except Exception:

        traceback.print_exc()

        sys.exit(1)
