// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  installAutoCapture,
  uninstallAutoCapture,
  setReportCallback,
  clearReportCallback,
} from '../src/core/capture.js';

beforeEach(() => {
  uninstallAutoCapture();
  clearReportCallback();
});

afterEach(() => {
  uninstallAutoCapture();
  clearReportCallback();
});

describe('capture hooks', () => {
  it('captures window.onerror via the registered callback', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    installAutoCapture({ onerror: true });
    window.dispatchEvent(new ErrorEvent('error', { message: 'boom', error: new Error('boom') }));
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).toHaveBeenCalled();
    const errArg = cb.mock.calls[0]?.[0];
    expect(errArg).toBeInstanceOf(Error);
    expect((cb.mock.calls[0]?.[1] as { tags: { source: string } }).tags.source).toBe('window.onerror');
  });

  it('captures unhandledrejection', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    installAutoCapture({ unhandledrejection: true });
    const ev = new Event('unhandledrejection') as unknown as PromiseRejectionEvent;
    Object.defineProperty(ev, 'reason', { value: new Error('rej') });
    window.dispatchEvent(ev);
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).toHaveBeenCalled();
    expect((cb.mock.calls[0]?.[1] as { tags: { source: string } }).tags.source).toBe('unhandledrejection');
  });

  it('captures console.error and forwards to original', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    const original = vi.spyOn(console, 'error');
    installAutoCapture({ consoleError: true });
    // eslint-disable-next-line no-console
    console.error('boom');
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).toHaveBeenCalled();
    expect(original).toHaveBeenCalledWith('boom');
    original.mockRestore();
  });

  it('skips install if all hooks are disabled', () => {
    const cb = vi.fn();
    setReportCallback(cb);
    const addEventListenerSpy = vi.spyOn(window, 'addEventListener');
    const consoleRef = console.error;
    installAutoCapture({ onerror: false, unhandledrejection: false, consoleError: false });
    const registered = addEventListenerSpy.mock.calls
      .map((args) => args[0])
      .filter((t) => typeof t === 'string');
    expect(registered).not.toContain('error');
    expect(registered).not.toContain('unhandledrejection');
    expect(console.error).toBe(consoleRef);
    expect(cb).not.toHaveBeenCalled();
    addEventListenerSpy.mockRestore();
  });

  it('uninstall restores console.error and removes listeners', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    const original = console.error;
    installAutoCapture({});
    expect(console.error).not.toBe(original);
    uninstallAutoCapture();
    expect(console.error).toBe(original);
    // eslint-disable-next-line no-console
    console.error('after');
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).not.toHaveBeenCalled();
  });

  it('default cfg turns all hooks on', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    installAutoCapture(undefined);
    window.dispatchEvent(new ErrorEvent('error', { message: 'x', error: new Error('x') }));
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).toHaveBeenCalled();
  });

  it('is idempotent — calling install twice does not stack callbacks', async () => {
    const cb = vi.fn();
    setReportCallback(cb);
    installAutoCapture({});
    installAutoCapture({});
    window.dispatchEvent(new ErrorEvent('error', { message: 'x', error: new Error('x') }));
    await new Promise((r) => setTimeout(r, 0));
    expect(cb).toHaveBeenCalledTimes(1);
  });
});
