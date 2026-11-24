// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { GameEvent } from '../types/engine'
import { useSimulationStore } from '../stores/simulation'
import EventLog from './EventLog.vue'

describe('EventLog', () => {
  beforeEach(() => setActivePinia(createPinia()))
  afterEach(() => vi.restoreAllMocks())

  it('suit le curseur en ne faisant défiler que la liste, jamais la fenêtre', async () => {
    const store = useSimulationStore()
    store.events = Array.from({ length: 50 }, (_, i) => ({ seq: i + 1, type: 'TurnStarted', payload: { turn: i + 1, activePlayer: 0 } }) as unknown as GameEvent)
    const scrollIntoView = vi.fn()
    Element.prototype.scrollIntoView = scrollIntoView
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    const w = mount(EventLog, { attachTo: document.body })
    const ol = w.find('ol').element as HTMLOListElement
    Object.defineProperty(ol, 'clientHeight', { value: 100 })
    vi.spyOn(ol, 'getBoundingClientRect').mockReturnValue({ top: 0 } as DOMRect)
    w.findAll('li').forEach((li, i) => {
      vi.spyOn(li.element, 'getBoundingClientRect').mockImplementation(() => ({ top: i * 20 - ol.scrollTop }) as DOMRect)
      Object.defineProperty(li.element, 'offsetHeight', { value: 20 })
    })

    store.cursor = 30
    await nextTick()
    await nextTick()
    expect(ol.scrollTop).toBe(29 * 20 + 20 - 100)
    store.cursor = 2
    await nextTick()
    await nextTick()
    expect(ol.scrollTop).toBe(20)
    expect(scrollIntoView).not.toHaveBeenCalled()
    expect(scrollTo).not.toHaveBeenCalled()
    w.unmount()
  })
})
