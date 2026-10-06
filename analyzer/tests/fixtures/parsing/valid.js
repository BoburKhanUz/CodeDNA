// A comment line.
import { readFile } from "node:fs";
import helper from './helper.js';

/* A block
   comment. */
export class Cart extends Base {
  #items = [];

  add(item, quantity = 1) {
    if (!item || quantity < 1) {
      return false;
    } else if (quantity > 10) {
      for (const _ of [1]) {
        while (quantity > 10) { quantity--; }
      }
    }
    return true;
  }
}

export const total = (items) => items.reduce((a, b) => a + b, 0);

function legacy(a, b, c) {
  try { return a ? b : c; } catch (e) { return null; }
}
