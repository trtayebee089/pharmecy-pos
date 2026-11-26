/** Shown until the shop sets its own name (Administration › Integrations › Website › Site name). */
export const DEFAULT_BRAND = 'Your Brand'

let pending: Promise<void> | null = null

/**
 * The shop's name for the sidebar, login page, receipts and printouts. Read once
 * per visit from the public site settings (no sign-in needed, so the login page
 * has it too). Call `refreshBrand()` after the Website settings are saved.
 */
export function useBrand() {
  const brand = useState<string>('brand', () => DEFAULT_BRAND)
  const loaded = useState<boolean>('brand-loaded', () => false)

  function refreshBrand(): Promise<void> {
    if (import.meta.server) return Promise.resolve()
    const config = useRuntimeConfig()
    if (config.public.useMocks) {
      loaded.value = true
      return Promise.resolve()
    }
    // https://host/api/pos/v1 -> https://host/api/v1/site-config
    const url = String(config.public.apiBase).replace(/\/pos\/v1\/?$/, '/v1/site-config')
    pending ??= $fetch<{ data?: { shop?: { siteName?: string } } }>(url, { headers: { Accept: 'application/json' } })
      .then((res) => { brand.value = String(res?.data?.shop?.siteName ?? '').trim() || DEFAULT_BRAND })
      .catch(() => { /* keep the current name */ })
      .finally(() => { loaded.value = true; pending = null })
    return pending
  }

  if (!loaded.value && !pending) refreshBrand()

  return { brand, refreshBrand }
}
