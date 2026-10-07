RATES = {
    "domestic": (500, 100),
    "europe": (900, 250),
    "world": (1500, 400),
}


def shipping_cost(weight_kg, zone, express):
    if zone not in RATES or not 0 < weight_kg <= 30:
        return -1
    base, per_kg = RATES[zone]
    return base * (2 if express else 1) + per_kg * weight_kg
