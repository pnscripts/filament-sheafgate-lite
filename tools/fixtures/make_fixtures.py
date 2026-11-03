"""Builds the spreadsheet fixtures used by the tests.

The workbook is written as flat ODS XML and converted by LibreOffice itself, so the
.xlsx/.ods/.xls files are real LibreOffice output. A 1904-date workbook is written with
openpyxl (LibreOffice always saves the 1900 system).

Usage: python tools/fixtures/make_fixtures.py <out-dir> <build-dir>
Needs: soffice (LibreOffice), openpyxl.
"""
import datetime
import os
import shutil
import subprocess
import sys
from xml.sax.saxutils import escape

OUT, BUILD = sys.argv[1], sys.argv[2]
os.makedirs(OUT, exist_ok=True)
work = os.path.join(BUILD, "fixtures")
os.makedirs(work, exist_ok=True)


def s(text):
    return f'<table:table-cell office:value-type="string"><text:p>{escape(text)}</text:p></table:table-cell>'


def f(value, style=None):
    st = f' table:style-name="{style}"' if style else ""
    return f'<table:table-cell{st} office:value-type="float" office:value="{value}"><text:p>{value}</text:p></table:table-cell>'


def d(iso):
    return f'<table:table-cell table:style-name="cdate" office:value-type="date" office:date-value="{iso}"><text:p>{iso}</text:p></table:table-cell>'


def dt(iso):
    return f'<table:table-cell table:style-name="cdatetime" office:value-type="date" office:date-value="{iso}"><text:p>{iso}</text:p></table:table-cell>'


def t(duration, shown):
    return f'<table:table-cell table:style-name="ctime" office:value-type="time" office:time-value="{duration}"><text:p>{shown}</text:p></table:table-cell>'


def b(value):
    v = "true" if value else "false"
    return f'<table:table-cell table:style-name="cbool" office:value-type="boolean" office:boolean-value="{v}"><text:p>{v.upper()}</text:p></table:table-cell>'


def formula(expr, value):
    return f'<table:table-cell table:formula="of:={expr}" office:value-type="float" office:value="{value}"><text:p>{value}</text:p></table:table-cell>'


def pct(value):
    return f'<table:table-cell table:style-name="cpct" office:value-type="percentage" office:value="{value}"><text:p>{value}</text:p></table:table-cell>'


E = "<table:table-cell/>"


def row(*cells):
    return "<table:table-row>" + "".join(cells) + "</table:table-row>"


def merged(text, span):
    return (f'<table:table-cell table:number-columns-spanned="{span}" table:number-rows-spanned="1" office:value-type="string"><text:p>{escape(text)}</text:p></table:table-cell>'
            + "<table:covered-table-cell/>" * (span - 1))


def table(name, rows):
    return f'<table:table table:name="{name}">' + "".join(rows) + "</table:table>"


products = table("Products", [
    row(merged("Spring price list 2026", 4)),
    row(E),
    row(s("SKU"), s("Name"), s("Price"), s("Launch date"), s("In stock"), s("Updated at"), s("Opens"), s("Total"), E, s("Barcode"), s("Discount")),
    row(s("00123"), s("Linen apron"), f("19.9"), d("2026-03-01"), b(True), dt("2026-03-01T14:30:00"), t("PT09H30M00S", "09:30"), formula("[.C4]*2", "39.8"), s("x"), f("4006381333931"), pct("0.15")),
    row(s("00124"), s("Ceramic mug"), f("7.5"), d("2026-04-15"), b(False), dt("2026-04-15T08:05:09"), t("PT18H00M00S", "18:00"), formula("[.C5]*2", "15"), E, f("12345678901234567890"), pct("0")),
    row(E),
    row(s("00125"), s("Чаша за чай"), f("0.1"), d("1999-12-31"), b(True), dt("2000-01-01T00:00:00"), t("PT00H00M00S", "00:00"), formula("[.C7]*2", "0.2"), E, f("-42"), pct("1")),
])

merged_header = table("Merged header", [
    row(s("Item"), merged("Size", 2), s("Unit\nprice")),
    row(s("Box"), f("10"), f("20"), f("3.5")),
])

categories = table("Categories", [
    row(s("Name"), s("Code")),
    row(s("Kitchen"), s("KIT")),
    row(s("Garden"), s("GAR")),
])

empty = table("Empty", [row(E)])

fods = f"""<?xml version="1.0" encoding="UTF-8"?>
<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
 xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
 xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
 xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
 xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"
 xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2"
 xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
 office:version="1.3" office:mimetype="application/vnd.oasis.opendocument.spreadsheet">
<office:automatic-styles>
 <number:date-style style:name="Ndate"><number:year number:style="long"/><number:text>-</number:text><number:month number:style="long"/><number:text>-</number:text><number:day number:style="long"/></number:date-style>
 <number:date-style style:name="Ndatetime"><number:year number:style="long"/><number:text>-</number:text><number:month number:style="long"/><number:text>-</number:text><number:day number:style="long"/><number:text> </number:text><number:hours number:style="long"/><number:text>:</number:text><number:minutes number:style="long"/><number:text>:</number:text><number:seconds number:style="long"/></number:date-style>
 <number:time-style style:name="Ntime"><number:hours number:style="long"/><number:text>:</number:text><number:minutes number:style="long"/></number:time-style>
 <number:boolean-style style:name="Nbool"><number:boolean/></number:boolean-style>
 <number:percentage-style style:name="Npct"><number:number number:decimal-places="0" number:min-integer-digits="1"/><number:text>%</number:text></number:percentage-style>
 <style:style style:name="cdate" style:family="table-cell" style:data-style-name="Ndate"/>
 <style:style style:name="cdatetime" style:family="table-cell" style:data-style-name="Ndatetime"/>
 <style:style style:name="ctime" style:family="table-cell" style:data-style-name="Ntime"/>
 <style:style style:name="cbool" style:family="table-cell" style:data-style-name="Nbool"/>
 <style:style style:name="cpct" style:family="table-cell" style:data-style-name="Npct"/>
</office:automatic-styles>
<office:body><office:spreadsheet>{products}{merged_header}{categories}{empty}</office:spreadsheet></office:body>
</office:document>
"""

src = os.path.join(work, "catalogue.fods")
with open(src, "w", encoding="utf-8") as fh:
    fh.write(fods)

profile = "file://" + os.path.abspath(os.path.join(BUILD, "lo"))
for ext in ("xlsx", "ods", "xls"):
    subprocess.run(["soffice", f"-env:UserInstallation={profile}", "--headless", "--convert-to", ext,
                    "--outdir", work, src], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    shutil.copy(os.path.join(work, f"catalogue.{ext}"), os.path.join(OUT, f"catalogue-libreoffice.{ext}"))

# 1904 date system (classic Mac Excel): LibreOffice cannot save it, openpyxl can.
from openpyxl import Workbook
from openpyxl.utils.datetime import CALENDAR_MAC_1904

wb = Workbook()
wb.epoch = CALENDAR_MAC_1904
ws = wb.active
ws.title = "Orders"
ws.append(["Order", "Ordered on", "Ships at"])
ws.append(["A-1", datetime.date(2026, 3, 1), datetime.datetime(2026, 3, 2, 16, 45, 0)])
ws["B2"].number_format = "yyyy-mm-dd"
ws["C2"].number_format = "yyyy-mm-dd hh:mm"
wb.save(os.path.join(OUT, "orders-1904.xlsx"))
print("fixtures written to", OUT)
