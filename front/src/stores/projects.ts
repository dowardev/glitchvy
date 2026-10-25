// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, isGlitchvyApiError } from '@/api/client'
import { useAuthStore } from './auth'
import type { Project, CreateProjectRequest, ApiKey, CreateApiKeyRequest, CreateApiKeyResponse } from '@/types'

export const useProjectsStore = defineStore('projects', () => {
  const list = ref<Project[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const selectedProject = ref<Project | null>(null)
  const apiKeys = ref<ApiKey[]>([])
  const apiKeysLoading = ref(false)

  function getToken(): string {
    return useAuthStore().token ?? ''
  }

  async function fetchAll(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      list.value = await api.listProjects(getToken())
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

  async function create(data: CreateProjectRequest): Promise<Project> {
    loading.value = true
    error.value = null
    try {
      const project = await api.createProject(data, getToken())
      list.value.push(project)
      return project
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error al crear el proyecto'
      }
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchApiKeys(projectId: number): Promise<void> {
    apiKeysLoading.value = true
    error.value = null
    try {
      apiKeys.value = await api.listApiKeys(projectId, getToken())
    } catch (err) {
      if (isGlitchvyApiError(err)) {
        error.value = err.message
      } else {
        error.value = 'Error de conexión'
      }
      throw err
    } finally {
      apiKeysLoading.value = false
    }
  }

  async function createApiKey(projectId: number, data: CreateApiKeyRequest): Promise<CreateApiKeyResponse> {
    const response = await api.createApiKey(projectId, data, getToken())
    apiKeys.value.push(response.api_key)
    return response
  }

  function selectProject(project: Project | null): void {
    selectedProject.value = project
  }

  return {
    list,
    loading,
    error,
    selectedProject,
    apiKeys,
    apiKeysLoading,
    fetchAll,
    create,
    fetchApiKeys,
    createApiKey,
    selectProject,
  }
})