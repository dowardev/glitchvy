// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect } from 'vitest';
import { buildScrubContext, scrub, scrubHeaders } from '../src/core/scrub.js';

describe('scrub', () => {
  it('redacts default PII field names regardless of case AND context', () => {
    const ctx = buildScrubContext({
      // Configure unrelated fields so we know the redaction comes from defaults.
      redactFields: ['name'],
      redactContexts: [],
    });
    // `password` is in the default globals and not inside any context here.
    const root = scrub({ password: 'p', other: 'ok' }, ctx);
    expect(root).toEqual({ password: '[REDACTED]', other: 'ok' });
  });

  it('does NOT redact configured redactFields when no redactContexts is set', () => {
    // The contract is: redactFields apply *inside* redactContexts. With an
    // empty redactContexts, configured fields stay.
    const ctx = buildScrubContext({
      redactFields: ['name', 'amount'],
      redactContexts: [],
    });
    const out = scrub({ name: 'Comida', amount: 100, other: 'ok' }, ctx);
    expect(out).toEqual({ name: 'Comida', amount: 100, other: 'ok' });
  });

  it('configured redactFields still match case-insensitively inside contexts', () => {
    const ctx = buildScrubContext({
      redactFields: ['name', 'amount'],
      redactContexts: ['entry'],
    });
    const out = scrub(
      { entry: { NAME: 'Comida', Amount: 100, other: 'ok' } },
      ctx,
    ) as { entry: Record<string, unknown> };
    expect(out.entry.NAME).toBe('[REDACTED]');
    expect(out.entry.Amount).toBe('[REDACTED]');
    expect(out.entry.other).toBe('ok');
  });

  it('redacts fields inside configured redactContexts', () => {
    const ctx = buildScrubContext({
      redactFields: ['name', 'amount', 'balance'],
      redactContexts: ['entry', 'supplier', 'account'],
    });
    const input = {
      entry: { id: 1, name: 'Comida' },
      supplier: { name: 'Acme', notes: 'top' },
      balance: 100,
      meta: { name: 'untouched at root' },
    };
    const out = scrub(input, ctx) as Record<string, unknown>;

    const entry = out.entry as Record<string, unknown>;
    expect(entry.id).toBe(1);
    expect(entry.name).toBe('[REDACTED]');

    const supplier = out.supplier as Record<string, unknown>;
    expect(supplier.name).toBe('[REDACTED]');
    expect(supplier.notes).toBe('top'); // not in redactFields

    // Root `balance` is NOT inside any configured context and not in
    // redactKeys, so it stays.
    expect(out.balance).toBe(100);

    // Root `meta.name` is also NOT inside a configured context.
    const meta = out.meta as Record<string, unknown>;
    expect(meta.name).toBe('untouched at root');
  });

  it('redacts deeply nested fields inside configured contexts', () => {
    const ctx = buildScrubContext({
      redactFields: ['amount'],
      redactContexts: ['entry'],
    });
    const input = { entry: { line: { amount: 42, label: 'ok' } } };
    const out = scrub(input, ctx) as { entry: { line: { amount: string; label: string } } };
    expect(out.entry.line.amount).toBe('[REDACTED]');
    expect(out.entry.line.label).toBe('ok');
  });

  it('redacts Bearer tokens inside string values', () => {
    const ctx = buildScrubContext();
    const out = scrub(
      { msg: 'failed: Bearer abc.def-ghi_123 something else' },
      ctx,
    ) as Record<string, string>;
    expect(out.msg).toBe('failed: Bearer [REDACTED] something else');
  });

  it('walks arrays element-wise', () => {
    const ctx = buildScrubContext();
    const out = scrub(
      { items: [{ token: 'a' }, { token: 'b' }] },
      ctx,
    );
    expect(out).toEqual({ items: [{ token: '[REDACTED]' }, { token: '[REDACTED]' }] });
  });

  it('preserves non-object non-string non-array values', () => {
    const ctx = buildScrubContext();
    expect(scrub(42, ctx)).toBe(42);
    expect(scrub(true, ctx)).toBe(true);
    expect(scrub(null, ctx)).toBe(null);
    expect(scrub(undefined, ctx)).toBe(undefined);
  });

  it('does not mutate the input', () => {
    const ctx = buildScrubContext();
    const input = { name: 'Comida' };
    const snapshot = JSON.stringify(input);
    scrub(input, ctx);
    expect(JSON.stringify(input)).toBe(snapshot);
  });

  describe('scrubHeaders', () => {
    it('redacts credential headers', () => {
      const out = scrubHeaders({
        Authorization: 'Bearer xxx',
        Cookie: 'session=abc',
        'X-API-Key': 'k',
        'Content-Type': 'application/json',
      });
      expect(out).toEqual({
        Authorization: '[REDACTED]',
        Cookie: '[REDACTED]',
        'X-API-Key': '[REDACTED]',
        'Content-Type': 'application/json',
      });
    });

    it('passes through other header keys unchanged', () => {
      const out = scrubHeaders({ 'X-Custom': 'ok' });
      expect(out).toEqual({ 'X-Custom': 'ok' });
    });
  });
});
