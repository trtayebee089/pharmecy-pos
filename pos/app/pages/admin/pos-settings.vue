<script setup lang="ts">
import { gsap } from 'gsap'
import { Save, ScanBarcode, CreditCard, LayoutGrid, Rows3, Check, ReceiptText, Printer, Plus, Pencil, Trash2, Zap, Cable, Globe, Monitor, CheckCircle2, KeyRound } from 'lucide-vue-next'
import type { PosConfig, PrinterProfile } from '~/services/settings'
const { brand } = useBrand()

/** Legacy "POS Setting" (setting/pos_setting) with a proper printer setup. */
definePageMeta({ title: 'POS settings' })
const api = useSettingsApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()

const c = ref<PosConfig | null>(null)
const tab = ref<'counter' | 'payments' | 'invoice' | 'printers'>('counter')
const saving = ref(false)
const dirty = ref(false)
const customers = ref<{ value: number; label: string }[]>([])
const billers = ref<{ value: number; label: string }[]>([])
const printNodeKey = ref('')
const panel = ref<HTMLElement | null>(null)

onMounted(async () => {
  const [cfg, cust] = await Promise.all([api.pos(), useCustomersApi().lookup('')])
  c.value = cfg
  customers.value = cust.map(x => ({ value: x.id, label: x.name }))
  billers.value = useApi().useMocks
    ? (await import('~/mocks/reports-extra')).billers.map(b => ({ value: b.id, label: b.name }))
    : []
  await nextTick()
  watch(c, () => (dirty.value = true), { deep: true })
})
watch(tab, () => nextTick(() => revealStagger(panel.value?.children ?? [], { y: 8, stagger: 0.05, duration: 0.35 })))

async function save() {
  if (!c.value) return
  if (c.value.productsPerPage < 6 || c.value.productsPerPage > 100) { toast.error('Products per page must be between 6 and 100'); tab.value = 'counter'; return }
  if (!c.value.paymentMethods.some(p => p.enabled && p.code === 'cash')) { toast.error('Cash must stay enabled', 'The counter needs at least cash payments.'); tab.value = 'payments'; return }
  saving.value = true
  try {
    await api.savePos(c.value, printNodeKey.value || undefined)
    useState<'grid' | 'classic'>('pos-layout').value = c.value.layout
    if (printNodeKey.value) { c.value.printNodeConfigured = true; printNodeKey.value = '' }
    dirty.value = false
    toast.success('POS settings saved', 'Counters pick up the change on their next sale.')
  }
  catch (e: any) { toast.error('Settings not saved', e?.message) }
  finally { saving.value = false }
}

// ---- Printers ----
const connections = [
  { value: 'browser', label: 'Browser print dialog', icon: Monitor, hint: 'Works everywhere; the cashier confirms each print.' },
  { value: 'qz', label: 'QZ Tray (silent)', icon: Zap, hint: 'Prints straight to a USB/LAN printer without a dialog; needs QZ Tray on the counter PC.' },
  { value: 'printnode', label: 'PrintNode (cloud)', icon: Globe, hint: 'Prints through the PrintNode service; needs an API key below.' },
  { value: 'network', label: 'Network ESC/POS', icon: Cable, hint: 'Raw ESC/POS to the printer IP (port 9100) via the counter agent.' },
] as const
const editOpen = ref(false)
const editing = ref<PrinterProfile | null>(null)
const testing = ref<number | null>(null)
function newPrinter() {
  editing.value = { id: 0, name: '', role: 'receipt', counter: 'Counter 1', connection: 'browser', deviceName: '', paper: '80mm', copies: 1, autoPrint: false, openCashDrawer: false, autoCut: true, isDefault: false }
  editOpen.value = true
}
function editPrinter(p: PrinterProfile) { editing.value = structuredClone(toRaw(p)); editOpen.value = true }
const printerError = computed(() => {
  const p = editing.value
  if (!p) return ''
  if (!p.name.trim()) return 'Give the printer a name'
  if ((p.connection === 'qz' || p.connection === 'printnode') && !p.deviceName.trim()) return 'Enter the printer name or ID'
  if (p.connection === 'network' && !/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/.test(p.deviceName.trim())) return 'Enter the printer IP, e.g. 192.168.1.50:9100'
  if (p.connection === 'printnode' && !c.value?.printNodeConfigured && !printNodeKey.value) return 'Add the PrintNode API key on this tab first'
  if (p.copies < 1 || p.copies > 5) return 'Copies must be 1–5'
  return ''
})
function savePrinter() {
  if (!c.value || !editing.value || printerError.value) return
  const p = editing.value
  if (p.role === 'label' && !['38x25mm', '50x30mm'].includes(p.paper)) p.paper = '38x25mm'
  if (p.role === 'a4') p.paper = 'A4'
  if (p.isDefault) c.value.printers.forEach(x => { if (x.role === p.role && x.counter === p.counter) x.isDefault = false })
  if (p.id) c.value.printers.splice(c.value.printers.findIndex(x => x.id === p.id), 1, p)
  else c.value.printers.push({ ...p, id: Math.max(0, ...c.value.printers.map(x => x.id)) + 1 })
  editOpen.value = false
}
async function removePrinter(p: PrinterProfile) {
  const { ok } = await ask({ title: `Remove ${p.name}?`, description: 'Counters using it will fall back to the browser print dialog.', confirmLabel: 'Remove' })
  if (ok && c.value) c.value.printers = c.value.printers.filter(x => x.id !== p.id)
}
async function test(p: PrinterProfile) {
  testing.value = p.id
  if (p.connection === 'browser') {
    const w = window.open('', '_blank', 'width=420,height=600')
    w?.document.write(`<pre style="font:12px monospace;width:72mm">${brand.value.toUpperCase()}\nTest page · ${p.name}\nPaper ${p.paper}\n${new Date().toLocaleString()}\n--------------------------------\nIf you can read this, printing works.</pre>`)
    w?.document.close(); w?.print()
    testing.value = null
    return
  }
  const res = await api.testPrint(p.id)
  testing.value = null
  res.ok ? toast.success(`${p.name}: ${res.message}`) : toast.error(`${p.name}: test failed`, res.message)
}
const roleLabel = { receipt: 'Receipt', a4: 'A4 documents', label: 'Barcode labels' }
const connIcon = (c: string) => connections.find(x => x.value === c)?.icon ?? Monitor
const preview = ref<HTMLElement | null>(null)
watch(() => c.value?.invoice.format, () => preview.value && !prefersReducedMotion() && gsap.fromTo(preview.value, { scale: 0.96, opacity: 0.6 }, { scale: 1, opacity: 1, duration: 0.35, ease: 'power2.out' }))
</script>

<template>
  <div>
    <UiPageHeader title="POS settings" description="Counter defaults, payment methods, invoice layout and printers.">
      <UiBadge v-if="dirty" tone="warning" dot>Unsaved changes</UiBadge>
      <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="!c || !dirty" @click="save">Save settings</UiButton>
    </UiPageHeader>

    <div v-if="!c" class="skeleton h-96 rounded-2xl" />
    <template v-else>
      <UiTabs v-model="tab" :tabs="[{ value: 'counter', label: 'Counter' }, { value: 'payments', label: 'Payment methods', count: c.paymentMethods.filter(p => p.enabled).length }, { value: 'invoice', label: 'Invoice & receipt' }, { value: 'printers', label: 'Printers', count: c.printers.length }]" class="mb-5" />

      <div ref="panel" class="space-y-5">
        <!-- Counter -->
        <template v-if="tab === 'counter'">
          <UiSection title="Defaults" description="Pre-selected on every new sale" :icon="ScanBarcode">
            <div class="grid gap-4 sm:grid-cols-3">
              <UiSelect v-model="c.defaultCustomerId" label="Default customer" :options="customers" />
              <UiSelect v-model="c.defaultWarehouseId" label="Default warehouse" :options="(auth.user?.warehouses ?? []).map(w => ({ value: w.id, label: w.name }))" />
              <UiSelect v-model="c.defaultBillerId" label="Default biller" :options="billers" hint="Billing identity printed on the invoice" />
            </div>
          </UiSection>
          <UiSection title="POS screen layout" description="Applies to every counter. Cashiers see the chosen layout the next time they open the POS." :icon="LayoutGrid">
            <template #actions>
              <a :href="`/pos?layout=${c.layout}`" target="_blank" rel="noopener"><UiButton size="sm" variant="soft">Preview {{ c.layout }}</UiButton></a>
            </template>
            <div class="grid gap-4 md:grid-cols-2">
              <button
                v-for="opt in ([
                  { value: 'grid', title: 'Grid', icon: LayoutGrid, text: 'Product cards with stock and expiry at a glance, cart panel on the right. Best for touch screens and browsing.' },
                  { value: 'classic', title: 'Classic', icon: Rows3, text: 'Table-based full-screen counter like the previous POS: search with a results table, cart table, big payment buttons. Best for barcode-scanner and keyboard use.' },
                ] as const)" :key="opt.value" type="button"
                class="group relative overflow-hidden rounded-2xl border-2 p-4 text-left transition"
                :class="c.layout === opt.value ? 'border-brand-500 bg-brand-50/40 shadow-[var(--shadow-glow)]' : 'border-slate-200 hover:border-slate-300'"
                :aria-pressed="c.layout === opt.value" @click="c.layout = opt.value"
              >
                <span v-if="c.layout === opt.value" class="absolute top-3 right-3 grid size-6 place-items-center rounded-full bg-brand-600 text-white"><Check class="size-3.5" /></span>
                <!-- mini preview -->
                <div class="mb-3 h-28 rounded-xl border border-slate-200 bg-slate-50 p-2">
                  <div v-if="opt.value === 'grid'" class="flex h-full gap-1.5">
                    <div class="flex flex-1 flex-col gap-1.5">
                      <div class="h-3 rounded bg-white ring-1 ring-slate-200" />
                      <div class="grid flex-1 grid-cols-4 gap-1">
                        <div v-for="i in 8" :key="i" class="rounded bg-white ring-1 ring-slate-200"><div class="m-1 h-1 w-3/4 rounded bg-slate-300" /><div class="mx-1 h-1 w-1/2 rounded bg-brand-300" /></div>
                      </div>
                    </div>
                    <div class="flex w-1/3 flex-col gap-1 rounded bg-white p-1 ring-1 ring-slate-200">
                      <div v-for="i in 3" :key="i" class="h-2 rounded bg-slate-200" /><div class="mt-auto h-3 rounded bg-brand-500" />
                    </div>
                  </div>
                  <div v-else class="flex h-full flex-col gap-1">
                    <div class="grid grid-cols-4 gap-1"><div v-for="i in 4" :key="i" class="h-2.5 rounded bg-white ring-1 ring-slate-200" /></div>
                    <div class="h-3 rounded bg-white ring-2 ring-brand-300" />
                    <div class="flex flex-1 flex-col gap-0.5 rounded bg-white p-1 ring-1 ring-slate-200"><div class="h-1.5 rounded bg-slate-200" /><div v-for="i in 3" :key="i" class="h-1.5 rounded bg-slate-100" /></div>
                    <div class="h-3 rounded bg-gradient-to-r from-brand-600 to-brand-800" />
                    <div class="flex justify-center gap-1"><span class="h-2.5 w-6 rounded bg-slate-600" /><span class="h-2.5 w-6 rounded bg-amber-400" /><span class="h-2.5 w-6 rounded bg-rose-500" /><span class="h-2.5 w-6 rounded bg-emerald-500" /></div>
                  </div>
                </div>
                <p class="flex items-center gap-2 font-display text-[15px] font-bold"><component :is="opt.icon" class="size-4 text-brand-600" />{{ opt.title }}</p>
                <p class="mt-1 text-[12.5px] text-slate-500">{{ opt.text }}</p>
              </button>
            </div>
          </UiSection>
          <UiSection title="Counter screen">
            <div class="grid gap-5 sm:grid-cols-2">
              <UiInput v-model.number="c.productsPerPage" type="number" label="Products shown per page" hint="Legacy: displayed number of product rows" />
              <div>
                <p class="mb-2 text-[13px] font-medium text-slate-700">Product grid</p>
                <UiSegmented v-model="c.productGrid" :options="[{ value: 'comfortable', label: 'Comfortable' }, { value: 'compact', label: 'Compact' }]" />
              </div>
              <UiSwitch v-model="c.touchKeyboard" label="On-screen keyboard" description="For touch-screen counters without a physical keyboard" />
              <UiSwitch v-model="c.sendSmsAfterSale" label="Send SMS after sale" description="Invoice total and points to customers with a phone number" />
              <UiSwitch v-model="c.requireCustomerForDue" label="Due needs a named customer" description="Walk-in sales must be paid in full" />
            </div>
          </UiSection>
        </template>

        <!-- Payments -->
        <UiSection v-else-if="tab === 'payments'" title="Payment methods at the counter" description="Each method posts to its account. Cash cannot be disabled." :icon="CreditCard">
          <div class="divide-y divide-slate-100">
            <div v-for="p in c.paymentMethods" :key="p.code" class="grid grid-cols-[minmax(0,1fr)] items-center gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_220px_160px_60px]">
              <div>
                <p class="text-sm font-semibold">{{ p.label }}</p>
                <p class="text-xs text-slate-500">Posts to {{ p.account }}</p>
              </div>
              <input v-model="p.account" class="h-9 rounded-lg border border-slate-200 px-3 text-sm focus:border-brand-400 focus:outline-none" :aria-label="`Account for ${p.label}`">
              <label class="flex items-center gap-2 text-xs text-slate-600"><input v-model="p.requiresReference" type="checkbox" class="size-4 accent-brand-600">Require reference / TrxID</label>
              <UiSwitch v-model="p.enabled" :label="undefined" :aria-label="`Enable ${p.label}`" :class="p.code === 'cash' && 'pointer-events-none opacity-60'" />
            </div>
          </div>
        </UiSection>

        <!-- Invoice -->
        <div v-else-if="tab === 'invoice'" class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_330px]">
          <UiSection title="Invoice & receipt" description="Legacy: A4 / thermal 80 mm / 58 mm" :icon="ReceiptText">
            <div class="space-y-5">
              <div>
                <p class="mb-2 text-[13px] font-medium text-slate-700">Format at the counter</p>
                <UiSegmented v-model="c.invoice.format" :options="[{ value: 'thermal80', label: 'Thermal 80 mm' }, { value: 'thermal58', label: 'Thermal 58 mm' }, { value: 'a4', label: 'A4 invoice' }]" />
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <UiSwitch v-model="c.invoice.showLogo" label="Print logo" />
                <UiSwitch v-model="c.invoice.showGeneric" label="Generic name under brand" />
                <UiSwitch v-model="c.invoice.showBatchExpiry" label="Batch & expiry per line" />
                <UiSwitch v-model="c.invoice.showSavings" label="Show customer savings" />
                <UiSwitch v-model="c.invoice.showQr" label="QR code of invoice number" />
              </div>
              <UiTextarea v-model="c.invoice.header" label="Header (shop name, address, phone)" :rows="3" />
              <UiTextarea v-model="c.invoice.footer" label="Footer" :rows="2" />
            </div>
          </UiSection>
          <div class="sticky top-20 h-fit">
            <p class="label mb-2">Preview</p>
            <div ref="preview" class="mx-auto rounded-xl border border-dashed border-slate-300 bg-white p-4 font-mono text-[10.5px] leading-snug text-slate-800 shadow-inner" :style="{ maxWidth: c.invoice.format === 'thermal58' ? '200px' : c.invoice.format === 'thermal80' ? '270px' : '100%' }">
              <div v-if="c.invoice.showLogo" class="mx-auto mb-1 grid size-8 place-items-center rounded-lg bg-brand-600 font-sans text-xs font-bold text-white">+</div>
              <p v-for="(line, i) in c.invoice.header.split('\n')" :key="i" class="text-center" :class="i === 0 && 'font-bold'">{{ line }}</p>
              <p class="my-1.5 border-t border-dashed border-slate-400" />
              <p class="flex justify-between"><span>Napa 500 mg ×2 strip</span><span>24.00</span></p>
              <p v-if="c.invoice.showGeneric" class="text-[9.5px] text-slate-500">Paracetamol</p>
              <p v-if="c.invoice.showBatchExpiry" class="text-[9.5px] text-slate-500">B: NA53G576 · Exp 07/27</p>
              <p class="mt-1 flex justify-between"><span>Seclo 20 mg ×1 strip</span><span>60.00</span></p>
              <p v-if="c.invoice.showGeneric" class="text-[9.5px] text-slate-500">Omeprazole</p>
              <p v-if="c.invoice.showBatchExpiry" class="text-[9.5px] text-slate-500">B: SE17A986 · Exp 12/26</p>
              <p class="my-1.5 border-t border-dashed border-slate-400" />
              <p class="flex justify-between font-bold"><span>TOTAL</span><span>84.00</span></p>
              <p v-if="c.invoice.showSavings" class="text-center text-[9.5px]">You saved ৳4.20 today</p>
              <div v-if="c.invoice.showQr" class="mx-auto my-1.5 size-12 bg-[repeating-conic-gradient(#0f1b2d_0_25%,#fff_0_50%)] bg-[length:8px_8px]" />
              <p class="mt-1 text-center text-[9.5px]">{{ c.invoice.footer }}</p>
            </div>
          </div>
        </div>

        <!-- Printers -->
        <template v-else>
          <UiSection title="Printers" description="One default printer per counter and document type. Labels and A4 use their own printers." :icon="Printer">
            <template #actions><UiButton size="sm" variant="soft" :icon="Plus" @click="newPrinter">Add printer</UiButton></template>
            <div class="grid gap-3 md:grid-cols-2">
              <div v-for="p in c.printers" :key="p.id" class="group rounded-2xl border border-slate-200 p-4 transition hover:border-brand-300 hover:shadow-[var(--shadow-card)]">
                <div class="flex items-start gap-3">
                  <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><component :is="connIcon(p.connection)" class="size-5" /></span>
                  <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-sm font-semibold">{{ p.name }}<UiBadge v-if="p.isDefault" tone="brand">Default</UiBadge></p>
                    <p class="text-xs text-slate-500">{{ roleLabel[p.role] }} · {{ p.counter }} · {{ p.paper }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ connections.find(x => x.value === p.connection)?.label }}<template v-if="p.deviceName"> · <span class="font-mono">{{ p.deviceName }}</span></template></p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                      <UiBadge v-if="p.autoPrint" tone="success">Auto print</UiBadge>
                      <UiBadge v-if="p.openCashDrawer" tone="info">Opens cash drawer</UiBadge>
                      <UiBadge v-if="p.autoCut" tone="neutral">Auto cut</UiBadge>
                      <UiBadge v-if="p.copies > 1" tone="neutral">{{ p.copies }} copies</UiBadge>
                    </div>
                  </div>
                </div>
                <div class="mt-3 flex justify-end gap-1">
                  <UiButton size="sm" :loading="testing === p.id" :icon="Printer" @click="test(p)">Test print</UiButton>
                  <UiButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${p.name}`" @click="editPrinter(p)" />
                  <UiButton size="sm" variant="ghost" :icon="Trash2" :aria-label="`Remove ${p.name}`" @click="removePrinter(p)" />
                </div>
              </div>
            </div>
          </UiSection>
          <UiSection title="PrintNode cloud printing" description="Only needed for PrintNode printers. The key is stored server-side and never shown again." :icon="KeyRound">
            <div class="flex flex-wrap items-end gap-3">
              <div class="w-full max-w-md"><UiInput v-model="printNodeKey" type="password" label="PrintNode API key" :placeholder="c.printNodeConfigured ? 'Configured · enter a new key to replace it' : 'Paste the API key'" autocomplete="off" /></div>
              <UiBadge v-if="c.printNodeConfigured" tone="success" size="md"><CheckCircle2 class="size-3.5" />Configured</UiBadge>
            </div>
          </UiSection>
        </template>
      </div>
    </template>

    <UiModal v-model="editOpen" :title="editing?.id ? 'Edit printer' : 'Add printer'" size="lg">
      <div v-if="editing" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
          <UiInput v-model="editing.name" label="Name" placeholder="e.g. Counter 1 receipt" />
          <UiSelect v-model="editing.role" label="Prints" :options="[{ value: 'receipt', label: 'Receipts' }, { value: 'a4', label: 'A4 documents' }, { value: 'label', label: 'Barcode labels' }]" />
          <UiInput v-model="editing.counter" label="Counter / location" />
        </div>
        <div>
          <p class="mb-2 text-[13px] font-medium text-slate-700">Connection</p>
          <div class="grid gap-2 sm:grid-cols-2">
            <button v-for="cn in connections" :key="cn.value" type="button" class="flex items-start gap-3 rounded-2xl border p-3 text-left transition" :class="editing.connection === cn.value ? 'border-brand-400 bg-brand-50/60 shadow-[var(--shadow-glow)]' : 'border-slate-200 hover:border-slate-300'" @click="editing.connection = cn.value">
              <component :is="cn.icon" class="mt-0.5 size-5 shrink-0 text-brand-600" />
              <span><span class="block text-sm font-semibold">{{ cn.label }}</span><span class="block text-xs text-slate-500">{{ cn.hint }}</span></span>
            </button>
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <UiInput v-if="editing.connection !== 'browser'" v-model="editing.deviceName" :label="editing.connection === 'network' ? 'Printer IP:port' : editing.connection === 'printnode' ? 'PrintNode printer ID' : 'Printer name (as in Windows)'" :placeholder="editing.connection === 'network' ? '192.168.1.50:9100' : ''" />
          <UiSelect v-if="editing.role === 'receipt'" v-model="editing.paper" label="Paper" :options="[{ value: '80mm', label: '80 mm' }, { value: '58mm', label: '58 mm' }]" />
          <UiSelect v-else-if="editing.role === 'label'" v-model="editing.paper" label="Label size" :options="[{ value: '38x25mm', label: '38 × 25 mm' }, { value: '50x30mm', label: '50 × 30 mm' }]" />
          <UiInput v-model.number="editing.copies" type="number" label="Copies" />
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <UiSwitch v-model="editing.isDefault" label="Default for this counter" />
          <UiSwitch v-if="editing.role === 'receipt'" v-model="editing.autoPrint" label="Print automatically after sale" />
          <UiSwitch v-if="editing.role === 'receipt' && editing.connection !== 'browser'" v-model="editing.openCashDrawer" label="Open cash drawer on cash sale" />
          <UiSwitch v-if="editing.role === 'receipt' && editing.connection !== 'browser'" v-model="editing.autoCut" label="Cut paper after receipt" />
        </div>
        <p v-if="printerError" class="text-sm font-medium text-rose-600">{{ printerError }}</p>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="editOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :disabled="!!printerError" @click="savePrinter">{{ editing?.id ? 'Update printer' : 'Add printer' }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
