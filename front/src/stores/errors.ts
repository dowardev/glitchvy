// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, isGlitchvyApiError } from '@/api/client'
import type { ErrorReportSummary, ErrorReportDetail, ErrorFilters, CursorPage, Status } from '@/types'

export const useErrorsStore = defineStore('errors', () => {
  const list = ref<ErrorReportSummary[]>([])
  const nextCursor = ref<string | null>(null)
  const prevCursor = ref<string | null>(null)
  const filters = ref<ErrorFilters>({})
  const detail = ref<ErrorReportDetail | null>(null)
  const loading = ref(false)
  const detailLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchPage(cursor?: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const requestFilters = { ...filters.value }
      if (cursor) {
        requestFilters.cursor = cursor
      }
      const response: CursorPage<ErrorReportSummary> = await api.listErrors(requestFilters)
      list.value = response.data
      nextCursor.value = response.next_cursor
      prevCursor.value = response.prev_cursor
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error de conexión'
      }
      throw err
    } finally {
      loading.value = false
    }
  }

  async function loadDetail(id: number): Promise<void> {
    detailLoading.value = true
    error.value = null
    try {
      detail.value = await api.getError(id)
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error al cargar el detalle'
      }
      throw err
    } finally {
      detailLoading.value = false
    }
  }

  async function setStatus(id: number, status: Status): Promise<void> {
    error.value = null
    try {
      const updated = await api.updateStatus(id, status)
      const index = list.value.findIndex(e => e.id === id)
      if (index !== -1) {
        list.value[index] = updated
      }
      if (detail.value?.id === id) {
        detail.value = { ...detail.value, status: updated.status }
      }
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error al actualizar el estado'
      }
      throw err
    }
  }

  function setFilters(partial: Partial<ErrorFilters>): void {
    filters.value = { ...filters.value, ...partial }
  }

  function clearDetail(): void {
    detail.value = null
  }

  function clearError(): void {
    error.value = null
  }

  return {
    list,
    nextCursor,
    prevCursor,
    filters,
    detail,
    loading,
    detailLoading,
    error,
    fetchPage,
    loadDetail,
    setStatus,
    setFilters,
    clearDetail,
    clearError,
  }
})