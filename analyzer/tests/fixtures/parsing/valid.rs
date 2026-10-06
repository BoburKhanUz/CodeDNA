use std::collections::HashMap;
use crate::model::{Item, Order};

// A comment line.
/// Doc comment.
pub struct Cart {
    items: HashMap<u32, u32>,
}

pub trait Priced {
    fn price(&self) -> u32;
}

impl Cart {
    pub fn add(&mut self, id: u32, quantity: u32) -> bool {
        if quantity == 0 || id == 0 {
            return false;
        }
        match quantity {
            1 => {}
            2 | 3 => {}
            _ => {}
        }
        true
    }
}

fn helper(values: &[u32]) -> u32 {
    values.iter().map(|v| v * 2).sum()
}
