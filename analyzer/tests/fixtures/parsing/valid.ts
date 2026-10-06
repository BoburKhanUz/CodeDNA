import { Injectable } from '@angular/core';
// A comment line.

export interface Repository<T> extends Base<T> {
  find(id: number): T;
}

export abstract class UserService implements Repository<User> {
  private cache = new Map<number, User>();

  constructor(private readonly http: Http) {}

  public find(this: UserService, id: number): User {
    const hit = this.cache.get(id);
    if (hit !== undefined && id > 0) {
      return hit;
    }
    switch (id) {
      case 0: throw new Error('zero');
      case 1: return this.load(id);
      default: return this.load(id);
    }
  }

  protected load(id: number): User { return id > 0 ? new User() : new User(); }
}

export enum Role { Admin, User }
