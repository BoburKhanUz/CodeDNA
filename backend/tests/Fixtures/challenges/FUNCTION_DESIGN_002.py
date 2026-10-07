def money(cents, currency):
    return f"{cents // 100}.{cents % 100:02d} {currency}"


def format_line(line):
    total = line["qty"] * line["unit_cents"]
    unit = money(line["unit_cents"], line["currency"])
    return f"{line['description']} | {line['qty']} x {unit} = {money(total, line['currency'])}"
