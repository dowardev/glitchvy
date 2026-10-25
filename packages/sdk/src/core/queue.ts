// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * IndexedDB-backed offline queue. Events whose POST failed are persisted
 * here and flushed on the `online` event.
 *
 * Storage shape: a single object store `queue` keyed by `eventId`. Upsert on
 * enqueue — re-queuing the same event replaces the previous copy.
 *
 * Test env: `fake-indexeddb/auto` is loaded by `tests/setup.ts`.
 */

import type { ReportEvent } from '../types.js';

const DB_NAME = 'glitchvy';
const STORE_NAME = 'queue';
const DB_VERSION = 1;

let dbPromise: Promise<IDBDatabase> | null = null;

function openDb(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise;
  if (typeof indexedDB === 'undefined') {
    return Promise.reject(new Error('IndexedDB is not available in this runtime.'));
  }
  dbPromise = new Promise<IDBDatabase>((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'eventId' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('IDB open failed'));
  });
  return dbPromise;
}

function reqToPromise<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('IDB request failed'));
  });
}

function txToPromise(tx: IDBTransaction): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error ?? new Error('IDB transaction failed'));
  });
}

export async function enqueue(event: ReportEvent): Promise<void> {
  const db = await openDb();
  const tx = db.transaction(STORE_NAME, 'readwrite');
  tx.objectStore(STORE_NAME).put(event);
  await txToPromise(tx);
}

/** Atomically read every queued event and remove them. */
export async function dequeueAll(): Promise<ReportEvent[]> {
  const db = await openDb();
  const tx = db.transaction(STORE_NAME, 'readwrite');
  const store = tx.objectStore(STORE_NAME);
  const events: ReportEvent[] = [];

  return new Promise<ReportEvent[]>((resolve, reject) => {
    const cursorReq = store.openCursor();
    cursorReq.onsuccess = () => {
      const cursor = cursorReq.result;
      if (cursor) {
        events.push(cursor.value as ReportEvent);
        cursor.delete();
        cursor.continue();
      }
    };
    cursorReq.onerror = () => reject(cursorReq.error ?? new Error('IDB cursor failed'));
    tx.oncomplete = () => resolve(events);
    tx.onerror = () => reject(tx.error ?? new Error('IDB transaction failed'));
  });
}

export async function remove(eventId: string): Promise<void> {
  const db = await openDb();
  const tx = db.transaction(STORE_NAME, 'readwrite');
  tx.objectStore(STORE_NAME).delete(eventId);
  await txToPromise(tx);
}

export async function size(): Promise<number> {
  const db = await openDb();
  const tx = db.transaction(STORE_NAME, 'readonly');
  const req = tx.objectStore(STORE_NAME).count();
  return reqToPromise<number>(req);
}

export async function clear(): Promise<void> {
  const db = await openDb();
  const tx = db.transaction(STORE_NAME, 'readwrite');
  tx.objectStore(STORE_NAME).clear();
  await txToPromise(tx);
}

/** Test-only helper. Production code never calls this. */
export function __resetDb(): void {
  dbPromise = null;
}
