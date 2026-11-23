export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info' | 'warning'
  title: string
  description?: string
}

let seq = 0

export function useToast() {
  const toasts = useState<Toast[]>('toasts', () => [])
  function push(kind: Toast['kind'], title: string, description?: string, ms = 4200) {
    const id = ++seq
    toasts.value = [...toasts.value, { id, kind, title, description }]
    setTimeout(() => dismiss(id), ms)
  }
  function dismiss(id: number) {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }
  return {
    toasts,
    dismiss,
    success: (t: string, d?: string) => push('success', t, d),
    error: (t: string, d?: string) => push('error', t, d, 6000),
    info: (t: string, d?: string) => push('info', t, d),
    warning: (t: string, d?: string) => push('warning', t, d, 5500),
  }
}
