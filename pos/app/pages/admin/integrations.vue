<script setup lang="ts">
import { Mail, MessageSquareText, Activity, Rss, CreditCard, Truck, Globe, Save, Send, Plus, Trash2, Copy } from 'lucide-vue-next'
import { SECRET_DRAFTS, type IntegrationGroup, type IntegrationsPayload, type KeyValue } from '~/services/integrations'
const { brand } = useBrand()

definePageMeta({ title: 'Integrations' })
const api = useIntegrationsApi()
const toast = useToast()

type Tab = 'mail' | 'sms' | 'tracking' | 'payments' | 'couriers' | 'website'
const tab = ref<Tab>('mail')
const tabs = [
  { value: 'mail' as Tab, label: 'Email' }, { value: 'sms' as Tab, label: 'SMS' }, { value: 'tracking' as Tab, label: 'Tracking & feed' },
  { value: 'payments' as Tab, label: 'Payments' }, { value: 'couriers' as Tab, label: 'Couriers' }, { value: 'website' as Tab, label: 'Website' },
]

const data = ref<IntegrationsPayload | null>(null)
/** The loaded settings; the template only reads it once `data` is set. */
const d = computed(() => data.value as IntegrationsPayload)
const error = ref('')
/** Secrets typed in this time ('group.name' → value) and secrets to remove ('group.name' → true). */
const sec = reactive<Record<string, string>>({})
const clr = reactive<Record<string, boolean>>({})
provide(SECRET_DRAFTS, { typed: sec, clear: clr })
const saving = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})

async function load() {
  error.value = ''
  try { data.value = await api.load() }
  catch (e: any) { error.value = e?.message ?? 'Could not load the settings.' }
}
onMounted(load)

async function save(group: IntegrationGroup, label: string) {
  if (!data.value) return
  saving.value = group
  errors.value = {}
  const values = Object.fromEntries(Object.entries(data.value[group]).filter(([k]) => !k.endsWith('Set')))
  const prefix = `${group}.`
  const mine = (k: string) => k.startsWith(prefix)
  const typed = Object.fromEntries(Object.entries(sec).filter(([k, v]) => mine(k) && v).map(([k, v]) => [k.slice(prefix.length), v]))
  const clear = Object.entries(clr).filter(([k, v]) => mine(k) && v).map(([k]) => k.slice(prefix.length))
  try {
    const saved = await api.save(group, values as any, typed, clear)
    ;(data.value as any)[group] = saved
    // The site name is the brand on every screen and printout.
    if (group === 'storefront') brand.value = String((saved as any)?.siteName ?? '').trim() || DEFAULT_BRAND
    for (const k of Object.keys(sec).filter(mine)) delete sec[k]
    for (const k of Object.keys(clr).filter(mine)) delete clr[k]
    toast.success(`${label} saved`)
  } catch (e: any) {
    errors.value = e?.errors ?? {}
    toast.error(`${label} not saved`, e?.message)
  } finally { saving.value = null }
}
const err = (k: string) => errors.value[k]?.[0]

// ---------------------------------------------------------------- tests
const mailTo = ref('')
const smsTo = ref('')
const smsText = ref('')
const testing = ref<string | null>(null)
const testResult = reactive<Record<string, { ok: boolean; message: string } | null>>({ mail: null, sms: null })
async function testMail() {
  testing.value = 'mail'
  testResult.mail = await api.testMail(mailTo.value)
  testing.value = null
}
async function testSms() {
  testing.value = 'sms'
  testResult.sms = await api.testSms(smsTo.value, smsText.value || undefined)
  testing.value = null
}

// ---------------------------------------------------------------- SMS template
const presetOptions = computed(() => [
  { value: 'custom', label: 'Custom (any provider)' },
  ...Object.entries(data.value?.smsPresets ?? {}).map(([value, p]) => ({ value, label: p.label })),
])
function applyPreset(code: string | number | null | undefined) {
  const s = data.value?.sms
  const p = data.value?.smsPresets[String(code)]
  if (!s) return
  s.provider = String(code)
  if (!p) return
  Object.assign(s, { method: p.method, url: p.url, bodyType: p.bodyType, phoneFormat: p.phoneFormat, successContains: p.successContains, params: JSON.parse(JSON.stringify(p.params)), headers: JSON.parse(JSON.stringify(p.headers)) })
}
const addRow = (rows: KeyValue[]) => rows.push({ key: '', value: '' })

async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); toast.success('Copied') } catch { toast.error('Copy failed', text) }
}
</script>

<template>
  <div>
    <UiPageHeader title="Integrations" description="Email, SMS, tracking, payment gateways, couriers and website details. Passwords and keys are stored encrypted and never shown again." />

    <UiTabs v-model="tab" :tabs="tabs" class="mb-5" />

    <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
    <div v-else-if="!data" class="grid gap-5 lg:grid-cols-2"><div v-for="i in 2" :key="i" class="skeleton h-64" /></div>

    <template v-else>
      <!-- ------------------------------------------------------------ Email -->
      <div v-if="tab === 'mail'" class="grid gap-5 lg:grid-cols-[1fr_360px]">
        <UiSection title="SMTP server" description="Used for password reset codes, staff temporary passwords and notices" :icon="Mail">
          <div class="space-y-4">
            <UiSwitch v-model="d.mail.enabled" label="Send email through this server" description="When off, the server's .env mail settings are used" />
            <div class="grid gap-4 sm:grid-cols-[1fr_120px]">
              <UiInput v-model="d.mail.host" label="SMTP host" placeholder="smtp.gmail.com" :error="err('host')" />
              <UiInput v-model.number="d.mail.port" type="number" label="Port" :error="err('port')" />
            </div>
            <div>
              <p class="mb-2 text-[13px] font-medium text-slate-700">Encryption</p>
              <UiSegmented v-model="d.mail.encryption" :options="[{ value: 'tls', label: 'TLS (587)' }, { value: 'ssl', label: 'SSL (465)' }, { value: 'none', label: 'None' }]" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.mail.username" label="Username" autocomplete="off" />
              <AdminSecretField field="mail.password" label="Password" :set="d.mail.passwordSet" hint="For Gmail use an app password" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.mail.fromAddress" label="From address" placeholder="no-reply@yourbrand.com" :error="err('fromAddress')" />
              <UiInput v-model="d.mail.fromName" label="From name" placeholder="Shop name if empty" />
            </div>
            <UiButton variant="primary" :icon="Save" :loading="saving === 'mail'" @click="save('mail', 'Email settings')">Save email settings</UiButton>
          </div>
        </UiSection>
        <UiSection title="Send a test" description="Save first, then send to yourself" :icon="Send">
          <div class="space-y-3">
            <UiInput v-model="mailTo" type="email" label="Send to" placeholder="you@example.com" />
            <UiButton :icon="Send" :loading="testing === 'mail'" :disabled="!mailTo" @click="testMail">Send test email</UiButton>
            <p v-if="testResult.mail" class="rounded-xl px-3 py-2 text-[13px]" :class="testResult.mail.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'">{{ testResult.mail.message }}</p>
          </div>
        </UiSection>
      </div>

      <!-- ------------------------------------------------------------ SMS -->
      <div v-else-if="tab === 'sms'" class="grid gap-5 lg:grid-cols-[1fr_360px]">
        <UiSection title="SMS gateway" description="Works with any provider that has an HTTP API" :icon="MessageSquareText">
          <div class="space-y-4">
            <UiSwitch v-model="d.sms.enabled" label="Send SMS" description="Due reminders and (when switched on below) order updates" />
            <UiSelect :model-value="d.sms.provider" label="Start from" :options="presetOptions" hint="Fills the request below; adjust it to your provider's documentation" @update:model-value="applyPreset" />
            <div class="grid gap-4 sm:grid-cols-[120px_1fr]">
              <div><p class="mb-2 text-[13px] font-medium text-slate-700">Method</p><UiSegmented v-model="d.sms.method" :options="[{ value: 'GET', label: 'GET' }, { value: 'POST', label: 'POST' }]" /></div>
              <UiInput v-model="d.sms.url" label="Gateway URL" placeholder="https://provider.example/api/send" :error="err('url')" />
            </div>
            <div>
              <p class="mb-2 text-[13px] font-medium text-slate-700">Send parameters as</p>
              <UiSegmented v-model="d.sms.bodyType" :options="[{ value: 'form', label: 'Form' }, { value: 'json', label: 'JSON' }, { value: 'query', label: 'Query string' }]" />
            </div>
            <div v-for="list in (['params', 'headers'] as const)" :key="list">
              <p class="mb-2 text-[13px] font-medium text-slate-700">{{ list === 'params' ? 'Parameters' : 'Headers' }}</p>
              <div class="space-y-2">
                <div v-for="(row, i) in d.sms[list]" :key="i" class="grid grid-cols-[1fr_1.4fr_auto] gap-2">
                  <UiInput v-model="row.key" placeholder="name" />
                  <UiInput v-model="row.value" placeholder="value, e.g. {message}" />
                  <button type="button" class="grid size-10 place-items-center rounded-xl text-slate-400 hover:bg-rose-50 hover:text-rose-600" :aria-label="`Remove ${row.key}`" @click="d.sms[list].splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <UiButton size="sm" variant="ghost" :icon="Plus" @click="addRow(d.sms[list])">Add {{ list === 'params' ? 'parameter' : 'header' }}</UiButton>
              </div>
            </div>
            <p class="rounded-xl bg-slate-50 px-3 py-2 text-[12.5px] text-slate-600">
              Placeholders: <code>{to}</code> <code>{message}</code> <code>{sender}</code> <code>{api_key}</code> <code>{api_secret}</code> <code>{basic_auth}</code> (base64 of key:secret)
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
              <AdminSecretField field="sms.apiKey" label="API key / token" :set="d.sms.apiKeySet" />
              <AdminSecretField field="sms.apiSecret" label="API secret (if any)" :set="d.sms.apiSecretSet" />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
              <UiInput v-model="d.sms.senderId" label="Sender ID / mask" />
              <UiSelect v-model="d.sms.phoneFormat" label="Number format" :options="[{ value: '8801', label: '8801XXXXXXXXX' }, { value: '+8801', label: '+8801XXXXXXXXX' }, { value: '01', label: '01XXXXXXXXX' }]" />
              <UiInput v-model="d.sms.successContains" label="Success text" hint="Response must contain this (blank = any 2xx)" />
            </div>
            <UiSwitch v-model="d.sms.sendOrderUpdates" label="SMS customers when their online order is confirmed, sent or delivered" />
            <UiButton variant="primary" :icon="Save" :loading="saving === 'sms'" @click="save('sms', 'SMS settings')">Save SMS settings</UiButton>
          </div>
        </UiSection>
        <UiSection title="Send a test" description="Uses the saved settings, even while SMS is off" :icon="Send">
          <div class="space-y-3">
            <UiInput v-model="smsTo" label="Mobile number" placeholder="01XXXXXXXXX" inputmode="tel" />
            <UiTextarea v-model="smsText" label="Message" :rows="3" :placeholder="`Test SMS from ${brand}.`" />
            <UiButton :icon="Send" :loading="testing === 'sms'" :disabled="!smsTo" @click="testSms">Send test SMS</UiButton>
            <p v-if="testResult.sms" class="break-words rounded-xl px-3 py-2 text-[13px]" :class="testResult.sms.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'">{{ testResult.sms.message }}</p>
          </div>
        </UiSection>
      </div>

      <!-- ------------------------------------------------------------ Tracking & feed -->
      <div v-else-if="tab === 'tracking'" class="grid gap-5 lg:grid-cols-2">
        <UiSection title="Tags & pixels" description="Loaded on the website; e-commerce events go to the dataLayer" :icon="Activity">
          <div class="space-y-4">
            <UiInput v-model="d.tracking.gtmId" label="Google Tag Manager ID" placeholder="GTM-XXXXXXX" :error="err('gtmId')" />
            <UiInput v-model="d.tracking.facebookPixelId" label="Facebook (Meta) Pixel ID" placeholder="123456789012345" :error="err('facebookPixelId')" />
            <UiInput v-model="d.tracking.ga4MeasurementId" label="Google Analytics 4 measurement ID" placeholder="G-XXXXXXXXXX" :error="err('ga4MeasurementId')" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.tracking.googleAdsId" label="Google Ads ID" placeholder="AW-123456789" :error="err('googleAdsId')" />
              <UiInput v-model="d.tracking.googleAdsPurchaseLabel" label="Purchase conversion label" />
            </div>
            <p class="text-[12.5px] text-slate-500">If you use Tag Manager for everything, fill only the GTM ID and set up the tags there; the other IDs load their own scripts.</p>
            <hr class="border-slate-100">
            <UiSwitch v-model="d.tracking.serverSideFacebook" label="Send purchases to Facebook from the server (Conversions API)" description="Deduplicated with the browser Pixel by event ID" />
            <div class="grid gap-4 sm:grid-cols-2">
              <AdminSecretField field="tracking.facebookCapiToken" label="Conversions API access token" :set="d.tracking.facebookCapiTokenSet" />
              <UiInput v-model="d.tracking.facebookTestEventCode" label="Test event code" hint="Only while testing in Events Manager" />
            </div>
            <UiSwitch v-model="d.tracking.serverSideGa4" label="Send purchases to GA4 from the server (Measurement Protocol)" />
            <AdminSecretField field="tracking.ga4ApiSecret" label="GA4 API secret" :set="d.tracking.ga4ApiSecretSet" />
            <UiButton variant="primary" :icon="Save" :loading="saving === 'tracking'" @click="save('tracking', 'Tracking settings')">Save tracking</UiButton>
          </div>
        </UiSection>
        <UiSection title="Product feed" description="For Facebook catalogue and Google Merchant Center (like CTX Feed)" :icon="Rss">
          <div class="space-y-4">
            <UiSwitch v-model="d.feed.enabled" label="Publish the product feed" />
            <UiInput v-model="d.feed.siteUrl" label="Website address" placeholder="https://yourbrand.com" hint="Product links in the feed point here" :error="err('siteUrl')" />
            <UiInput v-model="d.feed.brandFallback" label="Brand when a product has no manufacturer" placeholder="Shop name if empty" />
            <UiSwitch v-model="d.feed.inStockOnly" label="Only products in stock" />
            <UiSwitch v-model="d.feed.includePrescription" label="Include prescription medicines" description="Facebook and Google restrict ads for prescription drugs; leave off unless you are certified" />
            <AdminSecretField field="feed.token" label="Access token (optional)" :set="d.feed.tokenSet" hint="If set, add ?token=… to the feed URL" />
            <div class="space-y-2 rounded-xl bg-slate-50 p-3 text-[12.5px]">
              <p v-for="(url, name) in { 'XML (Facebook / Google)': d.urls.productFeedXml, 'CSV': d.urls.productFeedCsv }" :key="name" class="flex items-center justify-between gap-2">
                <span class="min-w-0"><span class="font-semibold text-slate-700">{{ name }}:</span> <span class="break-all text-slate-600">{{ url }}</span></span>
                <button type="button" class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-white" :aria-label="`Copy ${name} feed URL`" @click="copy(url)"><Copy class="size-4" /></button>
              </p>
            </div>
            <UiButton variant="primary" :icon="Save" :loading="saving === 'feed'" @click="save('feed', 'Product feed')">Save feed</UiButton>
          </div>
        </UiSection>
      </div>

      <!-- ------------------------------------------------------------ Payments -->
      <div v-else-if="tab === 'payments'" class="grid gap-5 lg:grid-cols-2">
        <UiSection title="SSLCommerz" description="Cards, bKash, Nagad, Rocket and net banking in one checkout" :icon="CreditCard">
          <div class="space-y-4">
            <UiSwitch v-model="d.sslcommerz.enabled" label="Offer on the website and app" />
            <UiSwitch v-model="d.sslcommerz.sandbox" label="Sandbox (test mode)" description="Turn off only with live credentials" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.sslcommerz.storeId" label="Store ID" :error="err('storeId')" />
              <AdminSecretField field="sslcommerz.storePassword" label="Store password" :set="d.sslcommerz.storePasswordSet" />
            </div>
            <UiInput v-model="d.sslcommerz.label" label="Name shown at checkout" />
            <p class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 p-3 text-[12.5px]"><span class="break-all"><span class="font-semibold">IPN URL:</span> {{ d.urls.sslcommerzIpn }}</span><button type="button" class="rounded-lg p-1.5 hover:bg-white" aria-label="Copy IPN URL" @click="copy(d.urls.sslcommerzIpn)"><Copy class="size-4" /></button></p>
            <UiButton variant="primary" :icon="Save" :loading="saving === 'sslcommerz'" @click="save('sslcommerz', 'SSLCommerz')">Save SSLCommerz</UiButton>
          </div>
        </UiSection>
        <UiSection title="bKash" description="bKash Tokenized Checkout with your merchant account" :icon="CreditCard">
          <div class="space-y-4">
            <UiSwitch v-model="d.bkash.enabled" label="Offer on the website and app" />
            <UiSwitch v-model="d.bkash.sandbox" label="Sandbox (test mode)" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.bkash.appKey" label="App key" :error="err('appKey')" />
              <AdminSecretField field="bkash.appSecret" label="App secret" :set="d.bkash.appSecretSet" />
              <UiInput v-model="d.bkash.username" label="Username" :error="err('username')" autocomplete="off" />
              <AdminSecretField field="bkash.password" label="Password" :set="d.bkash.passwordSet" />
            </div>
            <UiInput v-model="d.bkash.label" label="Name shown at checkout" />
            <UiButton variant="primary" :icon="Save" :loading="saving === 'bkash'" @click="save('bkash', 'bKash')">Save bKash</UiButton>
          </div>
        </UiSection>
        <UiSection title="Cash on delivery" :icon="CreditCard">
          <div class="space-y-4">
            <UiSwitch v-model="d.storefront.codEnabled" label="Offer cash on delivery" />
            <UiButton :icon="Save" :loading="saving === 'storefront'" @click="save('storefront', 'Cash on delivery')">Save</UiButton>
          </div>
        </UiSection>
      </div>

      <!-- ------------------------------------------------------------ Couriers -->
      <div v-else-if="tab === 'couriers'" class="grid gap-5 lg:grid-cols-2">
        <UiSection title="Steadfast" description="Book parcels from an order with “Send to courier”" :icon="Truck">
          <div class="space-y-4">
            <UiSwitch v-model="d.steadfast.enabled" label="Use Steadfast" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.steadfast.apiKey" label="API key" :error="err('apiKey')" />
              <AdminSecretField field="steadfast.secretKey" label="Secret key" :set="d.steadfast.secretKeySet" />
            </div>
            <UiInput v-model="d.steadfast.baseUrl" label="API base URL" :error="err('baseUrl')" />
            <AdminSecretField field="steadfast.webhookToken" label="Webhook bearer token" :set="d.steadfast.webhookTokenSet" hint="Choose a long random value and enter the same in the Steadfast panel" />
            <p class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 p-3 text-[12.5px]"><span class="break-all"><span class="font-semibold">Webhook URL:</span> {{ d.urls.steadfastWebhook }}</span><button type="button" class="rounded-lg p-1.5 hover:bg-white" aria-label="Copy Steadfast webhook URL" @click="copy(d.urls.steadfastWebhook)"><Copy class="size-4" /></button></p>
            <UiButton variant="primary" :icon="Save" :loading="saving === 'steadfast'" @click="save('steadfast', 'Steadfast')">Save Steadfast</UiButton>
          </div>
        </UiSection>
        <UiSection title="Pathao" description="Pathao Courier merchant API" :icon="Truck">
          <div class="space-y-4">
            <UiSwitch v-model="d.pathao.enabled" label="Use Pathao" />
            <UiSwitch v-model="d.pathao.sandbox" label="Sandbox (test mode)" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.pathao.clientId" label="Client ID" :error="err('clientId')" />
              <AdminSecretField field="pathao.clientSecret" label="Client secret" :set="d.pathao.clientSecretSet" />
              <UiInput v-model="d.pathao.username" label="Merchant email" :error="err('username')" autocomplete="off" />
              <AdminSecretField field="pathao.password" label="Merchant password" :set="d.pathao.passwordSet" />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
              <UiInput v-model="d.pathao.storeId" label="Store ID" hint="From the Pathao merchant panel" />
              <UiSelect v-model="d.pathao.defaultDeliveryType" label="Delivery" :options="[{ value: 48, label: 'Normal (48h)' }, { value: 12, label: 'On demand (12h)' }]" />
              <UiInput v-model.number="d.pathao.defaultWeightKg" type="number" label="Default weight (kg)" />
            </div>
            <AdminSecretField field="pathao.webhookSecret" label="Webhook secret" :set="d.pathao.webhookSecretSet" />
            <p class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 p-3 text-[12.5px]"><span class="break-all"><span class="font-semibold">Webhook URL:</span> {{ d.urls.pathaoWebhook }}</span><button type="button" class="rounded-lg p-1.5 hover:bg-white" aria-label="Copy Pathao webhook URL" @click="copy(d.urls.pathaoWebhook)"><Copy class="size-4" /></button></p>
            <UiButton variant="primary" :icon="Save" :loading="saving === 'pathao'" @click="save('pathao', 'Pathao')">Save Pathao</UiButton>
          </div>
        </UiSection>
      </div>

      <!-- ------------------------------------------------------------ Website -->
      <div v-else class="grid gap-5 lg:grid-cols-2">
        <UiSection title="Shop details" description="Shown in the website header, footer and messages" :icon="Globe">
          <div class="space-y-4">
            <UiInput v-model="d.storefront.siteName" label="Shop name" placeholder="Your Brand" hint="Your brand on the website, POS screens, receipts, emails and SMS" :error="err('siteName')" />
            <UiInput v-model="d.storefront.websiteUrl" label="Website address" placeholder="https://yourbrand.com" hint="Customers return here after paying online" :error="err('websiteUrl')" />
            <div class="grid gap-4 sm:grid-cols-2">
              <UiInput v-model="d.storefront.phone" label="Hotline" inputmode="tel" />
              <UiInput v-model="d.storefront.whatsapp" label="WhatsApp" inputmode="tel" />
            </div>
            <UiInput v-model="d.storefront.email" label="Email" :error="err('email')" />
            <UiTextarea v-model="d.storefront.address" label="Address" :rows="2" />
            <UiInput v-model="d.storefront.announcement" label="Top bar announcement" hint="Short line above the header; blank for the default" />
          </div>
        </UiSection>
        <UiSection title="Social & search" :icon="Globe">
          <div class="space-y-4">
            <UiInput v-model="d.storefront.facebookUrl" label="Facebook page" placeholder="https://facebook.com/…" :error="err('facebookUrl')" />
            <UiInput v-model="d.storefront.instagramUrl" label="Instagram" :error="err('instagramUrl')" />
            <UiInput v-model="d.storefront.youtubeUrl" label="YouTube" :error="err('youtubeUrl')" />
            <UiInput v-model="d.storefront.metaTitle" label="Search title" hint="Up to 70 characters" :error="err('metaTitle')" />
            <UiTextarea v-model="d.storefront.metaDescription" label="Search description" :rows="2" hint="Up to 170 characters" />
            <UiButton variant="primary" :icon="Save" :loading="saving === 'storefront'" @click="save('storefront', 'Website details')">Save website details</UiButton>
          </div>
        </UiSection>
      </div>
    </template>
  </div>
</template>
