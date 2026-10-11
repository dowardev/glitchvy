// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * PII scrubbing engine.
 *
 * Two layers of behavior:
 *
 *   1. **Global** redact keys (built-in defaults like `password`, `api_key`, `token`)
 *      apply *anywhere in the tree* without configuration. The default safety net.
 *
 *   2. **Contextual** redact keys (the consumer's `redactFields`) apply **only
 *      inside** a key whose name is one of the consumer's `redactContexts`
 *      (or recursively inside one of those). For example, with:
 *
 *          scrubRules: { redactFields: ['name', 'amount'],
 *                        redactContexts: ['entry', 'account'] }
 *
 *      input `{ entry: { name: 'foo' }, balance: 100 }` returns
 *      `{ entry: { name: '[REDACTED]' }, balance: 100 }`. The root `balance` is
 *      left alone because it isn't inside a configured context.
 *
 *   3. **String scrubbing**: any `Bearer xxx...` literal in a string value is
 *      replaced with `Bearer [REDACTED]`. We check for the substring before
 *      invoking `replace` to dodge the regex `lastIndex` gotcha with `g` flag.
 */

import type { ScrubRules } from '../types.js';

const MASK = '[REDACTED]';
const BEARER_PREFIX = 'Bearer ';
const BEARER_REGEX = /Bearer\s+[A-Za-z0-9._-]+/gi;

const DEFAULT_GLOBAL_REDACT_KEYS = new Set<string>([
  'password',
  'passwd',
  'secret',
  'token',
  'api_key',
  'apikey',
  'api-key',
  'access_token',
  'refresh_token',
  'credit_card',
  'creditcard',
  'card_number',
  'cardnumber',
  'cvv',
  'ssn',
  'tax_id',
]);

const DEFAULT_HEADER_REDACT_KEYS = new Set<string>([
  'cookie',
  'authorization',
  'set-cookie',
  'x-api-key',
  'x-csrf-token',
]);

export interface ScrubContext {
  /** Keys redacted anywhere in the tree. Defaults always apply. */
  globalRedactKeys: ReadonlySet<string>;
  /** Consumer-configured keys; only redactable while inside a contextual parent. */
  contextualRedactKeys: ReadonlySet<string>;
  /** Top-level subtree names that "open" the contextual behavior. */
  contextualParents: ReadonlySet<string>;
}

export function buildScrubContext(rules?: ScrubRules): ScrubContext {
  const globalRedactKeys = new Set<string>(DEFAULT_GLOBAL_REDACT_KEYS);
  const contextualRedactKeys = new Set<string>();
  const contextualParents = new Set<string>();
  if (rules?.redactFields) {
    for (const f of rules.redactFields) {
      if (typeof f === 'string') contextualRedactKeys.add(f.toLowerCase());
    }
  }
  if (rules?.redactContexts) {
    for (const c of rules.redactContexts) {
      if (typeof c === 'string') contextualParents.add(c.toLowerCase());
    }
  }
  return { globalRedactKeys, contextualRedactKeys, contextualParents };
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

export function scrub(value: unknown, ctx: ScrubContext): unknown {
  return walk(value, ctx, false);
}

function walk(value: unknown, ctx: ScrubContext, insideContext: boolean): unknown {
  if (value === null || value === undefined) return value;

  if (typeof value === 'string') {
    if (value.indexOf(BEARER_PREFIX) === -1) return value;
    return value.replace(BEARER_REGEX, `${BEARER_PREFIX}${MASK}`);
  }

  if (typeof value !== 'object') return value;

  if (Array.isArray(value)) {
    return value.map((item) => walk(item, ctx, insideContext));
  }

  if (!isPlainObject(value)) return value;

  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(value)) {
    const kLower = k.toLowerCase();
    const nowInside = insideContext || ctx.contextualParents.has(kLower);

    // Layer 1: globals always apply.
    if (ctx.globalRedactKeys.has(kLower)) {
      out[k] = MASK;
      continue;
    }
    // Layer 2: contextual keys apply only while inside a configured subtree.
    if (nowInside && ctx.contextualRedactKeys.has(kLower)) {
      out[k] = MASK;
      continue;
    }
    out[k] = walk(v, ctx, nowInside);
  }
  return out;
}

/**
 * Header-style scrub helper. Reserved for consumer-supplied headers attached
 * to the payload (`context.data.headers` etc.) — **do not** apply this to the
 * outbound request headers the SDK itself builds (the auth `Authorization`
 * Bearer must reach the server intact).
 */
export function scrubHeaders(
  headers: Record<string, string | undefined>,
): Record<string, string | undefined> {
  const out: Record<string, string | undefined> = {};
  for (const [k, v] of Object.entries(headers)) {
    if (DEFAULT_HEADER_REDACT_KEYS.has(k.toLowerCase())) {
      out[k] = MASK;
      continue;
    }
    out[k] = v;
  }
  return out;
}
