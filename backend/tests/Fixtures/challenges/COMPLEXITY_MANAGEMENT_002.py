SYMBOLS = set("!@#$%^&*-_")

RULES = [
    ("LENGTH", lambda p: len(p) >= 12),
    ("UPPER", lambda p: any(c.isupper() for c in p)),
    ("LOWER", lambda p: any(c.islower() for c in p)),
    ("DIGIT", lambda p: any(c.isdigit() for c in p)),
    ("SYMBOL", lambda p: any(c in SYMBOLS for c in p)),
    ("SPACE", lambda p: not any(c.isspace() for c in p)),
]


def check_password(password):
    return [name for name, passes in RULES if not passes(password)]
