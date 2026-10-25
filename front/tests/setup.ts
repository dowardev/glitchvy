// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { vi } from 'vitest'
import '@testing-library/jest-dom'
import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
  url: 'http://localhost',
  pretendToBeVisual: true,
})

global.window = dom.window as unknown as Window & typeof globalThis
global.document = dom.window.document
global.navigator = dom.window.navigator
global.HTMLElement = dom.window.HTMLElement
global.Element = dom.window.Element
global.Node = dom.window.Node
global.Event = dom.window.Event
global.CustomEvent = dom.window.CustomEvent
global.HTMLElement.prototype.scrollIntoView = vi.fn()
global.HTMLElement.prototype.focus = vi.fn()
global.HTMLElement.prototype.blur = vi.fn()

// Mock localStorage with a working implementation
const localStorageStore = new Map<string, string>()
const localStorageMock = {
  getItem: (key: string) => localStorageStore.get(key) || null,
  setItem: (key: string, value: string) => { localStorageStore.set(key, value) },
  removeItem: (key: string) => { localStorageStore.delete(key) },
  clear: () => { localStorageStore.clear() },
}

Object.defineProperty(global, 'localStorage', {
  value: localStorageMock,
  writable: true,
  configurable: true,
})

Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
  writable: true,
  configurable: true,
})

// Mock matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// Mock ResizeObserver
global.ResizeObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}))

// Mock IntersectionObserver
global.IntersectionObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}))

// Mock fetch
global.fetch = vi.fn()

// Mock console methods to reduce noise
const originalConsoleError = console.error
console.error = (...args) => {
  if (args[0]?.includes?.('Warning: ReactDOM.render is no longer supported')) {
    return
  }
  originalConsoleError.apply(console, args)
}