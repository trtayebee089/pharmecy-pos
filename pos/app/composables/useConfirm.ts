export interface ConfirmOptions {
  title: string
  description?: string
  confirmLabel?: string
  tone?: 'danger' | 'primary'
  requireReason?: boolean
}

interface ConfirmState extends ConfirmOptions {
  open: boolean
  reason: string
  resolve?: (v: { ok: boolean; reason: string }) => void
}

/** Promise-based confirmation dialog. Rendered once by <UiConfirmHost /> in app.vue. */
export function useConfirm() {
  const state = useState<ConfirmState>('confirm-dialog', () => ({ open: false, title: '', reason: '' }))
  function ask(opts: ConfirmOptions): Promise<{ ok: boolean; reason: string }> {
    return new Promise((resolve) => {
      state.value = { ...opts, open: true, reason: '', resolve }
    })
  }
  function settle(ok: boolean) {
    state.value.resolve?.({ ok, reason: state.value.reason })
    state.value = { ...state.value, open: false, resolve: undefined }
  }
  return { state, ask, settle }
}
