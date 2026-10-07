"use client";

import { type KeyboardEvent, type ReactNode, useId, useLayoutEffect, useMemo, useRef } from "react";

/**
 * A lightweight single-file source editor (Phase 16): a textarea over a
 * syntax-highlighted copy of its text. No terminal, no file system, no
 * execution: the text is only ever sent to the API. Highlighting is built
 * from React elements (never injected HTML).
 */

const KEYWORDS = new Set([
  "False", "None", "True", "and", "as", "assert", "async", "await", "break", "class", "continue", "def", "del", "elif", "else",
  "except", "finally", "for", "from", "global", "if", "import", "in", "is", "lambda", "match", "case", "nonlocal", "not", "or",
  "pass", "raise", "return", "try", "while", "with", "yield",
]);

const TOKEN = /("""[\s\S]*?(?:"""|$)|'''[\s\S]*?(?:'''|$)|"(?:\\.|[^"\\\n])*"?|'(?:\\.|[^'\\\n])*'?)|(#[^\n]*)|(\b\d+(?:\.\d+)?\b)|([A-Za-z_][A-Za-z0-9_]*)/g;

export function highlightPython(source: string): ReactNode[] {
  const nodes: ReactNode[] = [];
  let last = 0;
  let index = 0;
  for (const match of source.matchAll(TOKEN)) {
    const start = match.index ?? 0;
    if (start > last) nodes.push(source.slice(last, start));
    const [text, string, comment, number, word] = match;
    const kind = string ? "string" : comment ? "comment" : number ? "number" : word && KEYWORDS.has(word) ? "keyword" : null;
    nodes.push(kind ? <span key={index++} className={`token-${kind}`} data-token={kind}>{text}</span> : text);
    last = start + text.length;
  }
  if (last < source.length) nodes.push(source.slice(last));
  return nodes;
}

export function CodeEditor({
  value,
  onChange,
  label,
  readOnly = false,
}: {
  value: string;
  onChange?: (value: string) => void;
  label: string;
  readOnly?: boolean;
}) {
  const highlighted = useMemo(() => highlightPython(value), [value]);
  const pre = useRef<HTMLPreElement>(null);
  const textarea = useRef<HTMLTextAreaElement>(null);
  // Caret position to restore after an inserted indent, applied as soon as the new value renders.
  const caret = useRef<number | null>(null);
  // After Escape, the next Tab moves focus instead of indenting (no keyboard trap).
  const released = useRef(false);
  const hint = useId();

  useLayoutEffect(() => {
    if (caret.current !== null && textarea.current) {
      textarea.current.setSelectionRange(caret.current, caret.current);
      caret.current = null;
    }
  }, [value]);

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    if (event.key === "Escape") {
      released.current = true;
      return;
    }
    const wasReleased = released.current;
    released.current = false;
    if (event.key !== "Tab" || wasReleased || readOnly || !onChange) return;
    event.preventDefault();
    const { selectionStart, selectionEnd } = event.currentTarget;
    caret.current = selectionStart + 4;
    onChange(`${value.slice(0, selectionStart)}    ${value.slice(selectionEnd)}`);
  };

  return (
    <div className="code-editor relative overflow-hidden rounded-lg border bg-slate-950 font-mono text-sm leading-6 text-slate-100">
      <pre ref={pre} aria-hidden="true" className="pointer-events-none m-0 min-h-64 overflow-hidden p-3 whitespace-pre-wrap break-words">
        {highlighted}
        {"\n"}
      </pre>
      <textarea
        ref={textarea}
        aria-label={label}
        aria-describedby={readOnly ? undefined : hint}
        value={value}
        readOnly={readOnly}
        spellCheck={false}
        autoCapitalize="off"
        autoComplete="off"
        autoCorrect="off"
        onChange={(event) => onChange?.(event.target.value)}
        onKeyDown={onKeyDown}
        onScroll={(event) => {
          if (pre.current) pre.current.scrollTop = event.currentTarget.scrollTop;
        }}
        className="absolute inset-0 h-full w-full resize-none bg-transparent p-3 whitespace-pre-wrap break-words text-transparent caret-white outline-none selection:bg-slate-600/60"
      />
      {readOnly ? null : (
        <p id={hint} className="sr-only">
          Tab inserts four spaces. Press Escape, then Tab, to leave the editor.
        </p>
      )}
    </div>
  );
}
