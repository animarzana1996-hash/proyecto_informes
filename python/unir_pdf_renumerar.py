
import sys
import re
import fitz

# =============================================
# PATRON NUMERACION CARDIOLOGIA
# =============================================

PATRON = re.compile(
    r"Cardiolog[ií]a\s*[-–]\s*\d+\s*/\s*\d+\s*Page",
    re.IGNORECASE
)

# =============================================
# INTERPRETAR LISTA DE PAGINAS
# =============================================

def parsear_lista(valor, total, nombre):

    if not valor.strip():
        return []

    resultado = []

    for token in valor.split(","):

        token = token.strip()

        if not token.isdecimal():

            raise ValueError(
                f"{nombre}: solo numeros separados por comas."
            )

        num = int(token)

        if not 1 <= num <= total:

            raise ValueError(
                f"{nombre}: pagina {num} fuera de rango."
            )

        if num in resultado:

            raise ValueError(
                f"{nombre}: pagina repetida {num}."
            )

        resultado.append(num)

    return resultado


# =============================================
# SELECCIONAR PAGINAS Y ORDEN
# =============================================

def seleccionar_paginas(
    total,
    eliminadas,
    orden,
    nombre
):

    borrar = set(
        parsear_lista(
            eliminadas,
            total,
            f"{nombre}: eliminar"
        )
    )

    if orden.strip():

        orden_lista = parsear_lista(
            orden,
            total,
            f"{nombre}: ordenar"
        )

    else:

        orden_lista = list(
            range(1, total + 1)
        )

    if set(orden_lista) != set(
        range(1, total + 1)
    ):

        raise ValueError(
            f"{nombre}: el orden debe contener "
            "todas las paginas originales."
        )

    return [
        num - 1
        for num in orden_lista
        if num not in borrar
    ]


# =============================================
# RENUMERAR CARDIOLOGIA
# =============================================

def renumerar(pagina, numero, total):

    nuevo = (
        f"Cardiología - {numero}/{total} Page"
    )

    coincidencias = []

    datos = pagina.get_text("dict")

    for bloque in datos["blocks"]:

        for linea in bloque.get("lines", []):

            texto = "".join(
                span["text"]
                for span in linea["spans"]
            )

            if PATRON.search(texto):

                coincidencias.append(linea)

    if len(coincidencias) != 1:

        raise ValueError(
            f"Hoja {numero}: no se encontro "
            "una numeracion unica de Cardiologia."
        )

    linea = coincidencias[0]

    rect = fitz.Rect(
        linea["bbox"]
    )

    fuente = max(
        float(span["size"])
        for span in linea["spans"]
    )

    origen_y = float(
        linea["spans"][0]["origin"][1]
    )

    # BORRAR NUMERACION ORIGINAL

    zona = fitz.Rect(
        rect.x0 - 2,
        rect.y0 - 1,
        rect.x1 + 2,
        rect.y1 + 1
    )

    pagina.add_redact_annot(
        zona,
        fill=(1, 1, 1),
        cross_out=False
    )

    pagina.apply_redactions(
        images=0,
        graphics=0,
        text=0
    )

    # AJUSTAR NUEVA NUMERACION

    while (
        fitz.get_text_length(
            nuevo,
            fontname="helv",
            fontsize=fuente
        ) > max(rect.width + 18, 80)
    ):

        fuente -= 0.25

        if fuente < 6:

            raise ValueError(
                "La numeracion no cabe "
                f"en la hoja {numero}."
            )

    ancho = fitz.get_text_length(
        nuevo,
        fontname="helv",
        fontsize=fuente
    )

    x = (
        rect.x0 + rect.x1 - ancho
    ) / 2

    pagina.insert_text(
        (x, origen_y),
        nuevo,
        fontsize=fuente,
        fontname="helv",
        color=(0, 0, 0),
        overlay=True
    )


# =============================================
# UNIR PDF
# =============================================

def unir(
    pdf_informe,
    pdf_imagenes,
    salida,
    borrar_inf="",
    borrar_img="",
    orden_inf="",
    orden_img=""
):

    with fitz.open(pdf_informe) as informe, \
         fitz.open(pdf_imagenes) as imagenes:

        # PAGINAS DEL INFORME

        p_inf = seleccionar_paginas(
            len(informe),
            borrar_inf,
            orden_inf,
            "Informe"
        )

        # PAGINAS DE IMAGENES

        p_img = seleccionar_paginas(
            len(imagenes),
            borrar_img,
            orden_img,
            "Imagenes"
        )

        if not p_inf and not p_img:

            raise ValueError(
                "Debes conservar al menos una hoja."
            )

        with fitz.open() as resultado:

            # AGREGAR PAGINAS DEL INFORME

            for indice in p_inf:

                resultado.insert_pdf(
                    informe,
                    from_page=indice,
                    to_page=indice
                )

            # GUARDAR DONDE EMPIEZAN IMAGENES

            comienzo = resultado.page_count

            # AGREGAR PAGINAS DE CARDIOLOGIA

            for indice in p_img:

                resultado.insert_pdf(
                    imagenes,
                    from_page=indice,
                    to_page=indice
                )

            # RENUMERAR SOLO LAS IMAGENES

            for i in range(len(p_img)):

                pagina = resultado[
                    comienzo + i
                ]

                renumerar(
                    pagina,
                    i + 1,
                    len(p_img)
                )

            # GUARDAR PDF FINAL

            resultado.save(
                salida,
                garbage=4,
                deflate=True
            )

    print(
        f"PDF generado: "
        f"{len(p_inf)} paginas de informe, "
        f"{len(p_img)} paginas de imagenes."
    )


# =============================================
# EJECUCION DESDE PHP
# =============================================

if __name__ == "__main__":

    if len(sys.argv) != 8:

        print(
            "Uso: unir_pdf_renumerar.py "
            "pdf1 pdf2 salida quitar1 quitar2 "
            "orden1 orden2",
            file=sys.stderr
        )

        sys.exit(2)

    try:

        unir(*sys.argv[1:])

    except Exception as exc:

        print(
            f"ERROR: {exc}",
            file=sys.stderr
        )

        sys.exit(1)
