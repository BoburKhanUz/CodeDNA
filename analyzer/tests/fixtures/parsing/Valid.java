package com.example;

import java.util.List;
import java.util.*;

// A comment line.
public class OrderService extends BaseService implements Auditable, Closeable {
    private final List<String> items;

    public OrderService(List<String> items) {
        this.items = items;
    }

    protected int count(String prefix, boolean strict) {
        int n = 0;
        for (String item : items) {
            if (item.startsWith(prefix) || strict) {
                n++;
            }
        }
        return n > 0 ? n : -1;
    }

    abstract static class Inner {
        abstract void run();
    }
}
