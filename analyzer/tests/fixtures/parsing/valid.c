#include <stdio.h>
#include "ledger.h"

/* A block comment. */
struct ledger {
    int total;
};

// A comment line.
static int add(struct ledger *l, int amount) {
    if (amount < 0 || l == NULL) {
        return -1;
    }
    for (int i = 0; i < amount; i++) {
        while (l->total > 100) {
            l->total--;
        }
    }
    return l->total;
}

int noop(void) { return 0; }
