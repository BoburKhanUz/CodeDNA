"""Module docstring."""
# A comment line.
import os
from collections import OrderedDict


class Account(Base, metaclass=Meta):
    def deposit(self, amount, note=None):
        if amount <= 0 or note is None:
            raise ValueError("amount")
        for _ in range(3):
            while amount > 100:
                amount -= 1
        return amount


def helper(a, b, *rest):
    return [x for x in rest if x] if a else b
