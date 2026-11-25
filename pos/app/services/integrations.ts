import type { InjectionKey } from 'vue'

/**
 * Administration › Integrations (API: /admin/integrations, needs settings.manage).
 * Secrets are write-only: the API returns `<name>Set: boolean` instead of the value.
 * To save a secret send it; to keep it send nothing; to remove it list it in `clear`.
 */
export type KeyValue = { key: string; value: string }

export interface IntegrationGroups {
  mail: { enabled: boolean; host: string; port: number; encryption: 'none' | 'tls' | 'ssl'; username: string; fromAddress: string; fromName: string; passwordSet: boolean }
  sms: {
    enabled: boolean; provider: string; method: 'GET' | 'POST'; url: string; bodyType: 'form' | 'json' | 'query'; params: KeyValue[]; headers: KeyValue[]
    senderId: string; phoneFormat: '01' | '8801' | '+8801'; successContains: string; sendOrderUpdates: boolean; sendAfterSale: boolean
    apiKeySet: boolean; apiSecretSet: boolean
  }
  tracking: {
    gtmId: string; facebookPixelId: string; ga4MeasurementId: string; googleAdsId: string; googleAdsPurchaseLabel: string
    serverSideFacebook: boolean; facebookTestEventCode: string; serverSideGa4: boolean; facebookCapiTokenSet: boolean; ga4ApiSecretSet: boolean
  }
  feed: { enabled: boolean; siteUrl: string; brandFallback: string; inStockOnly: boolean; includePrescription: boolean; tokenSet: boolean }
  sslcommerz: { enabled: boolean; sandbox: boolean; storeId: string; label: string; storePasswordSet: boolean }
  bkash: { enabled: boolean; sandbox: boolean; appKey: string; username: string; label: string; appSecretSet: boolean; passwordSet: boolean }
  steadfast: { enabled: boolean; apiKey: string; baseUrl: string; secretKeySet: boolean; webhookTokenSet: boolean }
  pathao: {
    enabled: boolean; sandbox: boolean; clientId: string; username: string; storeId: string; defaultItemType: number; defaultDeliveryType: number
    defaultWeightKg: number; clientSecretSet: boolean; passwordSet: boolean; webhookSecretSet: boolean
  }
  storefront: {
    siteName: string; phone: string; whatsapp: string; email: string; address: string; facebookUrl: string; instagramUrl: string; youtubeUrl: string
    metaTitle: string; metaDescription: string; announcement: string; codEnabled: boolean
  }
}

export type IntegrationGroup = keyof IntegrationGroups

export interface SmsPreset { label: string; method: 'GET' | 'POST'; url: string; bodyType: 'form' | 'json' | 'query'; phoneFormat: '01' | '8801' | '+8801'; params: KeyValue[]; headers: KeyValue[]; successContains: string }

export interface IntegrationsPayload extends IntegrationGroups {
  smsPresets: Record<string, SmsPreset>
  urls: { productFeedXml: string; productFeedCsv: string; steadfastWebhook: string; pathaoWebhook: string; sslcommerzIpn: string }
}

export interface TestResult { ok: boolean; message: string; response?: string | null }

export function useIntegrationsApi() {
  const api = useApi('admin')
  return {
    load(): Promise<IntegrationsPayload> {
      return api.get('/admin/integrations')
    },
    /** `secrets` holds only the secrets typed in this time; `clear` lists the ones to remove. */
    save<G extends IntegrationGroup>(group: G, values: Partial<IntegrationGroups[G]>, secrets: Record<string, string> = {}, clear: string[] = []): Promise<IntegrationGroups[G]> {
      return api.put(`/admin/integrations/${group}`, { ...values, ...secrets, clear })
    },
    async testMail(to: string): Promise<TestResult> {
      try { return await api.post('/admin/integrations/mail/test', { to }) }
      catch (e: any) { return { ok: false, message: e?.message ?? 'Test failed.' } }
    },
    async testSms(to: string, message?: string): Promise<TestResult> {
      try { return await api.post('/admin/integrations/sms/test', { to, message }) }
      catch (e: any) { return { ok: false, message: e?.message ?? 'Test failed.' } }
    },
  }
}

/** Secrets typed on the Integrations page ('group.name' → value) and the ones to remove. */
export interface SecretDrafts { typed: Record<string, string>; clear: Record<string, boolean> }
export const SECRET_DRAFTS: InjectionKey<SecretDrafts> = Symbol('secret-drafts')
