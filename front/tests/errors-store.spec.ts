// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({
  api: {
    listErrors: vi.fn(),
    getError: vi.fn(),
    updateStatus: vi.fn(),
    screenshotUrl: vi.fn(),
  },
  isGlitchvyApiError: vi.fn((err: unknown) => err instanceof Error && 'status' in err),
  GlitchvyApiError: class extends Error {
    constructor(public status: number, public code: string, message: string) {
      super(message)
      this.name = 'GlitchvyApiError'
    }
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

import { api } from '@/api/client'
import { useErrorsStore } from '@/stores/errors'

describe('errors store', () => {
  let errorsStore: ReturnType<typeof useErrorsStore>

  beforeEach(() => {
    setActivePinia(createPinia())
    errorsStore = useErrorsStore()
    vi.resetAllMocks()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('initializes with empty state', () => {
    expect(errorsStore.list).toEqual([])
    expect(errorsStore.nextCursor).toBeNull()
    expect(errorsStore.prevCursor).toBeNull()
    expect(errorsStore.filters).toEqual({})
    expect(errorsStore.detail).toBeNull()
    expect(errorsStore.loading).toBe(false)
    expect(errorsStore.detailLoading).toBe(false)
    expect(errorsStore.error).toBeNull()
  })

  it('fetches page successfully', async () => {
    const mockResponse = {
      data: [
        { id: 1, project_id: 1, level: 'error', message: 'Test error', type: 'Error', status: 'open', count: 5, last_seen_at: '', first_seen_at: '' },
      ],
      next_cursor: 'next123',
      prev_cursor: null,
    }
    vi.mocked(api.listErrors).mockResolvedValueOnce(mockResponse)

    await errorsStore.fetchPage()

    expect(errorsStore.list).toEqual(mockResponse.data)
    expect(errorsStore.nextCursor).toBe('next123')
    expect(errorsStore.prevCursor).toBeNull()
    expect(errorsStore.loading).toBe(false)
    expect(errorsStore.error).toBeNull()
  })

  it('applies filters when fetching', async () => {
    vi.mocked(api.listErrors).mockResolvedValueOnce({ data: [], next_cursor: null, prev_cursor: null })

    errorsStore.setFilters({ level: 'error', status: 'open', search: 'test' })
    await errorsStore.fetchPage()

    expect(api.listErrors).toHaveBeenCalledWith(
      expect.objectContaining({
        level: 'error',
        status: 'open',
        search: 'test',
      }),
      expect.any(String)
    )
  })

  it('handles fetch error', async () => {
    const error = new Error('Network error')

    vi.mocked(api.listErrors).mockRejectedValueOnce(error)

    await expect(errorsStore.fetchPage()).rejects.toThrow()

    expect(errorsStore.error).toBe('Error de conexión')
    expect(errorsStore.loading).toBe(false)
  })

  it('loads detail successfully', async () => {
    const detail = {
      id: 1,
      project_id: 1,
      level: 'error',
      message: 'Test error',
      type: 'Error',
      status: 'open',
      count: 5,
      last_seen_at: '',
      first_seen_at: '',
      stack_trace: 'stack trace',
      breadcrumbs: [],
      context: {},
      tags: {},
      screenshots: [],
    }
    vi.mocked(api.getError).mockResolvedValueOnce(detail)

    await errorsStore.loadDetail(1)

    expect(errorsStore.detail).toEqual(detail)
    expect(errorsStore.detailLoading).toBe(false)
    expect(errorsStore.error).toBeNull()
  })

  it('updates status successfully', async () => {
    const detail = {
      id: 1,
      project_id: 1,
      level: 'error',
      message: 'Test error',
      type: 'Error',
      status: 'open',
      count: 5,
      last_seen_at: '',
      first_seen_at: '',
      stack_trace: null,
      breadcrumbs: [],
      context: {},
      tags: {},
      screenshots: [],
    }
    errorsStore.detail = detail
    errorsStore.list = [detail]

    const updated = { ...detail, status: 'resolved' as const }
    vi.mocked(api.updateStatus).mockResolvedValueOnce(updated)

    await errorsStore.setStatus(1, 'resolved')

    expect(errorsStore.detail?.status).toBe('resolved')
    expect(errorsStore.list[0].status).toBe('resolved')
    expect(errorsStore.error).toBeNull()
  })

  it('handles status update error', async () => {
    const error = new Error('Failed to update')

    vi.mocked(api.updateStatus).mockRejectedValueOnce(error)

    await expect(errorsStore.setStatus(1, 'resolved')).rejects.toThrow()

    expect(errorsStore.error).toBe('Error al actualizar el estado')
  })

  it('setFilters merges partial filters', () => {
    errorsStore.setFilters({ level: 'error' })
    errorsStore.setFilters({ status: 'open' })

    expect(errorsStore.filters).toEqual({ level: 'error', status: 'open' })
  })

  it('clearDetail resets detail', () => {
    errorsStore.detail = { id: 1 } as any
    errorsStore.clearDetail()
    expect(errorsStore.detail).toBeNull()
  })

  it('clearError resets error', () => {
    errorsStore.error = 'Some error'
    errorsStore.clearError()
    expect(errorsStore.error).toBeNull()
  })
})