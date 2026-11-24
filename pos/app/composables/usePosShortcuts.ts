/**
 * Counter keyboard map shared by both POS layouts.
 * F1 help · F2 search · F4 customer · F7 drafts · F8 hold · F9 pay · F10 custom item · Ctrl+Del clear
 */
export interface PosShortcutHandlers {
  help: () => void
  search: () => void
  customer: () => void
  drafts: () => void
  hold: () => void
  pay: () => void
  custom?: () => void
  clear?: () => void
}

export const POS_SHORTCUTS: [string, string][] = [
  ['F2', 'Focus search / scan barcode'],
  ['↑ ↓', 'Move through results'],
  ['Enter', 'Add highlighted or scanned item'],
  ['F4', 'Select customer'],
  ['F7', 'Recent transactions & drafts'],
  ['F8', 'Hold current sale as draft'],
  ['F9', 'Payment'],
  ['F10', 'Add custom item'],
  ['Ctrl + Delete', 'Clear the cart'],
  ['Ctrl + Enter', 'Complete payment'],
  ['Ctrl + K', 'Command palette'],
]

export function usePosShortcuts(h: PosShortcutHandlers) {
  useEventListener('keydown', (e: KeyboardEvent) => {
    const map: Record<string, (() => void) | undefined> = {
      F1: h.help, F2: h.search, F4: h.customer, F7: h.drafts, F8: h.hold, F9: h.pay, F10: h.custom,
    }
    if (e.ctrlKey && e.key === 'Delete') { e.preventDefault(); h.clear?.(); return }
    const fn = map[e.key]
    if (fn) { e.preventDefault(); fn() }
  })
}
