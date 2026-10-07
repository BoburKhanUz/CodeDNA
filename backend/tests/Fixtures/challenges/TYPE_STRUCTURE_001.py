class Stock:
    def __init__(self):
        self.quantities = {}

    def add(self, sku, qty):
        self.quantities[sku] = self.quantities.get(sku, 0) + qty

    def remove(self, sku, qty):
        if self.quantities.get(sku, 0) < qty:
            return False
        self.quantities[sku] -= qty
        return True

    def count(self, sku):
        return self.quantities.get(sku, 0)


def low_stock(stock, threshold):
    return sorted(sku for sku, qty in stock.quantities.items() if qty < threshold)


def run_inventory(operations):
    stock = Stock()
    handlers = {
        "add": lambda op: stock.add(op[1], op[2]),
        "remove": lambda op: stock.remove(op[1], op[2]),
        "count": lambda op: stock.count(op[1]),
        "low": lambda op: low_stock(stock, op[1]),
    }
    return [handlers[op[0]](op) for op in operations]
