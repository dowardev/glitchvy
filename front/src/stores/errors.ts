// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Errors store — list view state + cursor pagination + detail cache + status
 * mutations. All API calls go through {@link api}; this store is just glue.
 */

import { defineStore } from 'pinia';
import { api } from '../api/client.js';
import { useAuthStore } from './auth.js';
import type {
  ErrorFilters,
  ErrorReportDetail,
  ErrorReportSummary,
  Status,
} from '../types.js';

export interface ErrorsState {
  list: ErrorReportSummary[];
  nextCursor: string | null;
  prevCursor: string | null;
  loading: boolean;
  error: string | null;
  filters: ErrorFilters;
  detail: ErrorReportDetail | null;
  detailLoading: boolean;
  detailError: string | null;
}

const DEFAULT_FILTERS: ErrorFilters = { per_page: 25 };

export const useErrorsStore = defineStore('errors', {
  state: (): ErrorsState => ({
    list: [],
    nextCursor: null,
    prevCursor: null,
    loading: false,
    error: null,
    filters: { ...DEFAULT_FILTERS },
    detail: null,
    detailLoading: false,
    detailError: null,
  }),

  actions: {
    setFilters(partial: Partial<ErrorFilters>): void {
      this.filters = { ...this.filters, ...partial };
    },

    reset(): void {
      this.list = [];
      this.nextCursor = null;
      this.prevCursor = null;
    },

    setTokenAwareFilters(partial: Partial<ErrorFilters>): void {
      this.filters = { ...this.filters, ...partial };
      void this;
    },

    async fetchPage(cursor?: string): Promise<void> {
      const auth = useAuthStore();
      if (!auth.token) {
        this.error = 'Not authenticated.';
        return;
      }
      this.loading = true;
      this.error = null;
      try {
        const page = await api.listErrors(
          { ...this.filters, cursor },
          auth.token,
        );
        this.list = page.data;
        this.nextCursor = page.next_cursor;
        this.prevCursor = page.prev_cursor;
      } catch (err) {
        this.error = err instanceof Error ? err.message : 'Failed to load errors';
        this.list = [];
        this.nextCursor = null;
        this.prevCursor = null;
      } finally {
        this.loading = false;
      }
    },

    async loadDetail(id: number): Promise<void> {
      const auth = useAuthStore();
      this.detail = null;
      this.detailLoading = true;
      this.detailError = null;
      try {
        this.detail = await api.getError(id, auth.token);
      } catch (err) {
        this.detailError = err instanceof Error ? err.message : 'Failed to load error';
      } finally {
        this.detailLoading = false;
      }
    },

    async setStatus(id: number, status: Status): Promise<void> {
      const auth = useAuthStore();
      try {
        await api.updateStatus(id, status, auth.token);
      } catch (err) {
        const message = err instanceof Error ? err.message : 'Failed to update status';
        throw new Error(message);
      }
      // Update the local cache so the UI doesn't need a refetch.
      const idx = this.list.findIndex((r) => r.id === id);
      if (idx !== -1) {
        const current = this.list[idx];
        if (current) {
          this.list[idx] = { ...current, status };
        }
      }
      if (this.detail && this.detail.id === id) {
        this.detail = { ...this.detail, status };
      }
    },
  },
});
