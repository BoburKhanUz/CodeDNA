TAX_RATES = {"DE": 19, "FR": 20, "US": 0}


def subtotal(items):
    return sum(item["qty"] * item["unit_cents"] for item in items)


def discount(amount, coupon):
    if coupon == "SAVE10":
        return amount * 10 // 100
    if coupon == "FLAT500":
        return min(500, amount)
    return 0


def summarize_order(order):
    gross = subtotal(order["items"])
    off = discount(gross, order.get("coupon"))
    tax = (gross - off) * TAX_RATES.get(order["country"], 0) // 100
    return {
        "item_count": sum(item["qty"] for item in order["items"]),
        "subtotal_cents": gross,
        "discount_cents": off,
        "tax_cents": tax,
        "total_cents": gross - off + tax,
    }
