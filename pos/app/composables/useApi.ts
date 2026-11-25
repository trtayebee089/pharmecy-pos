import type { FetchOptions } from 'ofetch'

/** Normalised API error: every UI error state reads this shape. */
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

function readCookie(name: string): string | null {
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return m ? decodeURIComponent(m[1]!) : null
}

let csrfReady: Promise<void> | null = null

/**
 * Central client for the Laravel API (Sanctum SPA cookie auth).
 * - credentials: 'include' so the session cookie travels cross-subdomain
 * - X-XSRF-TOKEN from the XSRF-TOKEN cookie; /sanctum/csrf-cookie fetched once
 * - 401 → back to login; 419 → refresh CSRF and retry once; 422 → field errors
 * No tokens are stored in localStorage.
 */
/**
 * API domains already served by the Laravel API. While the mock layer is on,
 * listing a domain in NUXT_PUBLIC_LIVE_DOMAINS (comma separated) sends just
 * that domain to the real API, so finished endpoints can be used while the
 * rest of the UI still runs on mocks. With mocks off, everything is live.
 */
export type ApiDomain = 'auth' | 'catalog' | 'customers' | 'sales' | 'dashboard' | 'purchases' | 'registers' | 'loyalty' | 'orders' | 'hr' | 'inventory' | 'finance' | 'reports'

export function useApi(domain?: ApiDomain) {
  const config = useRuntimeConfig()
  const base = config.public.apiBase as string
  const origin = new URL(base).origin

  async function ensureCsrf() {
    csrfReady ??= $fetch(`${origin}/sanctum/csrf-cookie`, { credentials: 'include' }).then(() => undefined)
    return csrfReady
  }

  async function request<T>(path: string, opts: FetchOptions<'json'> = {}, retried = false): Promise<T> {
    const method = (opts.method ?? 'GET').toUpperCase()
    if (method !== 'GET') await ensureCsrf()
    const xsrf = readCookie('XSRF-TOKEN')
    try {
      return await $fetch<T>(path, {
        baseURL: base,
        credentials: 'include',
        ...opts,
        headers: {
          Accept: 'application/json',
          ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
          ...(opts.headers as Record<string, string> | undefined),
        },
      } as any) as T
    }
    catch (e: any) {
      const status = e?.response?.status ?? 0
      const body = e?.data ?? {}
      if (status === 419 && !retried) {
        csrfReady = null
        return request<T>(path, opts, true)
      }
      if (status === 401) {
        useAuthStore().clear()
        await navigateTo('/login')
      }
      throw new ApiError(body.message ?? (status ? `Request failed (${status})` : 'Network error, check the connection'), status, body.errors ?? {})
    }
  }

  return {
    get: <T>(path: string, query?: Record<string, any>, signal?: AbortSignal) => request<T>(path, { query, signal }),
    post: <T>(path: string, body?: any, idempotencyKey?: string) =>
      request<T>(path, { method: 'POST', body, headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {} }),
    put: <T>(path: string, body?: any) => request<T>(path, { method: 'PUT', body }),
    del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
    useMocks: Boolean(config.public.useMocks) && !(domain && String(config.public.liveDomains ?? '').split(',').map(s => s.trim()).includes(domain)),
  }
}

/** Simulated latency for the dev mock layer so loading states are exercised. */
export const mockDelay = (ms = 350) => new Promise(r => setTimeout(r, ms + Math.random() * 250))
