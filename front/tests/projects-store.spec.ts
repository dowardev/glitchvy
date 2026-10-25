// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/client', () => ({
  api: {
    listProjects: vi.fn(),
    createProject: vi.fn(),
    createApiKey: vi.fn(),
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
import { useProjectsStore } from '@/stores/projects'

describe('projects store', () => {
  let projectsStore: ReturnType<typeof useProjectsStore>

  beforeEach(() => {
    setActivePinia(createPinia())
    projectsStore = useProjectsStore()
    vi.resetAllMocks()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('initializes with empty state', () => {
    expect(projectsStore.list).toEqual([])
    expect(projectsStore.loading).toBe(false)
    expect(projectsStore.error).toBeNull()
    expect(projectsStore.selectedProject).toBeNull()
    expect(projectsStore.apiKeys).toEqual([])
    expect(projectsStore.apiKeysLoading).toBe(false)
  })

  it('fetches all projects successfully', async () => {
    const projects = [
      { id: 1, name: 'Project 1', created_at: '', updated_at: '' },
      { id: 2, name: 'Project 2', created_at: '', updated_at: '' },
    ]
    vi.mocked(api.listProjects).mockResolvedValueOnce(projects)

    await projectsStore.fetchAll()

    expect(projectsStore.list).toEqual(projects)
    expect(projectsStore.loading).toBe(false)
    expect(projectsStore.error).toBeNull()
  })

  it('handles fetch all error', async () => {
    const error = new Error('Network error')
    vi.mocked(api.listProjects).mockRejectedValueOnce(error)

    await expect(projectsStore.fetchAll()).rejects.toThrow()

    expect(projectsStore.error).toBe('Error de conexión')
    expect(projectsStore.loading).toBe(false)
  })

  it('creates project successfully', async () => {
    const newProject = { id: 3, name: 'New Project', created_at: '', updated_at: '' }
    vi.mocked(api.createProject).mockResolvedValueOnce(newProject)

    const result = await projectsStore.create({ name: 'New Project' })

    expect(result).toEqual(newProject)
    expect(projectsStore.list).toContainEqual(newProject)
    expect(projectsStore.loading).toBe(false)
    expect(projectsStore.error).toBeNull()
  })

  it('handles create project error', async () => {
    const error = new Error('Failed to create')
    vi.mocked(api.createProject).mockRejectedValueOnce(error)

    await expect(projectsStore.create({ name: 'New Project' })).rejects.toThrow()

    expect(projectsStore.error).toBe('Error al crear el proyecto')
    expect(projectsStore.loading).toBe(false)
  })

  it('creates API key successfully', async () => {
    const apiKey = { id: 1, project_id: 1, name: 'Test Key', prefix: 'glitchvy_', last_used_at: null, created_at: '' }
    const response = { api_key: apiKey, plaintext: 'glitchvy_abc123' }
    vi.mocked(api.createApiKey).mockResolvedValueOnce(response)

    const result = await projectsStore.createApiKey(1, { name: 'Test Key' })

    expect(result).toEqual(response)
    expect(projectsStore.apiKeys).toContainEqual(apiKey)
  })

  it('selectProject sets selected project', () => {
    const project = { id: 1, name: 'Project 1', created_at: '', updated_at: '' }
    projectsStore.selectProject(project)
    expect(projectsStore.selectedProject).toEqual(project)

    projectsStore.selectProject(null)
    expect(projectsStore.selectedProject).toBeNull()
  })
})