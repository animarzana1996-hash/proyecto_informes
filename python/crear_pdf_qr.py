import sys
import os
import qrcode
from fpdf import FPDF

def generar_qr_en_pdf(url_informe, codigo_unico, ruta_pdf_salida):
    carpeta = os.path.dirname(ruta_pdf_salida)
    if carpeta:
        os.makedirs(carpeta, exist_ok=True)

    ruta_qr_temp = os.path.join(carpeta, f"temp_qr_{codigo_unico}.png")
    qr = qrcode.QRCode(
        version=2,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=10,
        border=3,
    )
    qr.add_data(url_informe)
    qr.make(fit=True)

    img_qr = qr.make_image(fill_color="#0f294a", back_color="white")
    img_qr.save(ruta_qr_temp)

    pdf = FPDF(orientation="P", unit="mm", format="A4")
    pdf.add_page()

    pdf.set_fill_color(15, 41, 74)
    pdf.rect(0, 0, 210, 28, style="F")
    pdf.set_text_color(255, 255, 255)
    pdf.set_font("Helvetica", "B", 15)
    pdf.set_xy(15, 9)
    pdf.cell(0, 10, "CODIGO QR - INFORME FINAL", ln=True)

    pdf.set_text_color(20, 20, 20)
    pdf.set_draw_color(15, 41, 74)
    pdf.set_line_width(0.6)
    pdf.rect(45, 50, 120, 145)

    pdf.set_font("Helvetica", "B", 11)
    pdf.set_xy(45, 56)
    pdf.cell(120, 8, f"Codigo de Informe: {codigo_unico}", align="C", ln=True)

    pdf.image(ruta_qr_temp, x=70, y=70, w=70, h=70)

    pdf.set_font("Helvetica", "", 9)
    pdf.set_xy(50, 148)
    pdf.multi_cell(110, 5, "Escanee este codigo QR para visualizar el informe final.", align="C")

    pdf.set_font("Helvetica", "", 8)
    pdf.set_text_color(90, 90, 90)
    pdf.set_xy(50, 166)
    pdf.multi_cell(110, 4.5, url_informe, align="C")

    pdf.output(ruta_pdf_salida)

    if os.path.exists(ruta_qr_temp):
        os.remove(ruta_qr_temp)

if __name__ == "__main__":
    generar_qr_en_pdf(sys.argv[1], sys.argv[2], sys.argv[3])