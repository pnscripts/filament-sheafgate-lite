"""Writes tools/demo/spring-price-update.xlsx, the file the buyer check and the screenshots import.
Usage: python tools/demo/make_demo_file.py   (needs openpyxl)"""
import datetime as dt
import os
from openpyxl import Workbook
from openpyxl.styles import Font

wb = Workbook()
wb.properties.creator = "PN Scripts"
wb.properties.lastModifiedBy = "PN Scripts"
ws = wb.active
ws.title = "Spring update"
ws["A1"] = "Spring price update 2026"
ws["A1"].font = Font(bold=True, size=14)
ws.merge_cells("A1:H1")
headers = ["SKU", "Name", "Category", "Price", "Stock", "Launch date", "Active", "Note"]
ws.append([])
ws.append(headers)
for c in ws[3]:
    c.font = Font(bold=True)
D = dt.date
rows = [
    ["PN-1040", "Linen Cushion Cover", "Home", 26.00, 110, D(2026, 2, 1), "yes", "new price list"],
    ["PN-1047", "Oak Serving Board", "KIT", 39.50, 42, D(2026, 1, 15), "yes", None],
    ["PN-1054", "Enamel Camp Mug", "Outdoor", 16.50, 280, D(2026, 4, 1), "yes", "back in the range"],
    ["PN-1075", "Wool Picnic Blanket", "Outdoor", 69.00, 18, None, "no", "agreed price"],
    ["PN-1089", "Bamboo Utensil Set", "Kitchn", 15.00, 200, D(2026, 3, 15), "yes", None],
    ["PN-1096", "Linen Napkins, set of 4", "Home", "12,50", 140, D(2026, 3, 1), "yes", None],
    ["PN-1103", "Desk Organiser", "Stationery", 22.00, 60, D(2026, 3, 1), "yes", None],
    ["PN-1110", None, "Outdoor", 9.90, 75, None, "no", "name missing"],
    ["PN-1117", "Herb Planter Box", "Outdoor", 34.00, 25, D(2026, 4, 10), "maybe", None],
    ["PN-1103", "Desk Organiser", "Stationery", 22.00, 65, D(2026, 3, 1), "yes", "second line"],
    ["PN-1124", "Ceramic Tea Cup", "Kitchen", 11.00, 300, D(2026, 3, 20), "yes", None],
]
for r in rows:
    ws.append(r)
for row in ws.iter_rows(min_row=4):
    row[3].number_format = "0.00"
    row[5].number_format = "yyyy-mm-dd"
for col, width in zip("ABCDEFGH", [12, 26, 12, 9, 8, 13, 8, 20]):
    ws.column_dimensions[col].width = width
notes = wb.create_sheet("Notes")
notes.append(["Prices are in EUR and include VAT."])
out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "spring-price-update.xlsx")
wb.save(out)
print(out)
