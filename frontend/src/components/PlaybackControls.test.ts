// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSimulationStore } from '../stores/simulation'
import PlaybackControls from './PlaybackControls.vue'

function press(key: string, target: EventTarget = window, init: KeyboardEventInit = {}): KeyboardEvent {
  const e = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  target.dispatchEvent(e)
  return e
}

describe('PlaybackControls', () => {
  beforeEach(() => setActivePinia(createPinia()))
  afterEach(() => vi.restoreAllMocks())

  it('propose 0.5x / 1x / 2x / 4x et règle la cadence du store', async () => {
    const store = useSimulationStore()
    const w = mount(PlaybackControls)
    expect(w.findAll('option').map((o) => o.text())).toEqual(['0.5x', '1x', '2x', '4x'])
    expect((w.find('select').element as HTMLSelectElement).value).toBe('250')
    await w.find('select').setValue('125')
    expect(store.speedMs).toBe(125)
    w.unmount()
  })

  it('espace bascule lecture / pause, flèches font un pas', () => {
    const store = useSimulationStore()
    const play = vi.spyOn(store, 'play')
    const pause = vi.spyOn(store, 'pause')
    const step = vi.spyOn(store, 'step')
    const back = vi.spyOn(store, 'stepBack')
    const w = mount(PlaybackControls)

    expect(press(' ').defaultPrevented).toBe(true)
    expect(play).toHaveBeenCalledTimes(1)
    store.playing = true
    press(' ')
    expect(pause).toHaveBeenCalledTimes(1)
    press('ArrowRight')
    press('ArrowLeft')
    expect(step).toHaveBeenCalledTimes(1)
    expect(back).toHaveBeenCalledTimes(1)
    store.pause()
    w.unmount()
  })

  it('ignore les raccourcis pendant une saisie ou avec un modificateur', () => {
    const store = useSimulationStore()
    const play = vi.spyOn(store, 'play')
    const step = vi.spyOn(store, 'step')
    const w = mount(PlaybackControls)
    const input = document.createElement('input')
    document.body.appendChild(input)
    expect(press(' ', input).defaultPrevented).toBe(false)
    press('ArrowRight', input)
    press(' ', window, { ctrlKey: true })
    expect(play).not.toHaveBeenCalled()
    expect(step).not.toHaveBeenCalled()
    input.remove()
    w.unmount()
  })

  it('retire son écouteur au démontage', () => {
    const store = useSimulationStore()
    const step = vi.spyOn(store, 'step')
    mount(PlaybackControls).unmount()
    press('ArrowRight')
    expect(step).not.toHaveBeenCalled()
  })
})
