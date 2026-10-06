import React from "react";

type Props = { title: string };

export function Header({ title }: Props) {
  return title ? <h1 className="t">{title}</h1> : null;
}
