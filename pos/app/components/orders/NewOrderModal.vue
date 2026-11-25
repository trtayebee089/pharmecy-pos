<script setup lang="ts">
import { Trash2, PhoneCall, Truck } from 'lucide-vue-next'
import type { Product } from '~/types/domain'
import type { OnlineOrder, ShippingZone } from '~/types/orders'

/**
 * Phone / WhatsApp order taken by staff. The API prices every line from the
 * retail list and adds the zone's shipping (free above its minimum); stock is
 * only checked here and taken when the order is confirmed.
 */
const open = defineModel<boolean>({ default: false })
const emit = defineEmits<{ placed: [order: OnlineOrder] }>()

const api = useOnlineOrdersApi()
const zonesRes = useZonesResource()
const toast = useToast()

interface Line { key: string; product: Product; unitId: number; qty: number }
const form = reactive({ customerId: null as number | null, phone: '', name: '', address: '', zoneId: null as number | null, note: '' })
const lines = ref<Line[]>([])
const zones = ref<ShippingZone[]>([])
const known = ref<string | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
let key = ''

watch(open, async (o) => {
  if (!o) return
  Object.assign(form, { customerId: null, phone: '', name: '', address: '', zoneId: null, note: '' })
  lines.value = []
  known.value = null
  errors.value = {}
  key = crypto.randomUUID()
  try { zones.value = (await zonesRes.all()).filter(z => z.isActive) }
  catch (e: any) { toast.error('Could not load zones', e?.message) }
})

/** Known phone: fill the customer's name and address. */
async function lookupPhone() {
  const phone = normalizeBdPhone(form.phone)
  form.customerId = null
  known.value = null
  if (!phone) return
  try {
    const match = (await useCustomersApi().lookup(phone)).find(c => c.phone === phone)
    if (match) {
      form.customerId = match.id
      form.name = match.name
      known.value = `${match.name} · existing customer`
    }
  }
  catch { /* lookup is a convenience only */ }
}

watchDebounced(() => form.phone, lookupPhone, { debounce: 400 })

function add(p: Product) {
  const unit = p.units.find(u => u.isSaleDefault) ?? p.units[0]
  if (!unit) return
  const same = lines.value.find(l => l.product.id === p.id && l.unitId === unit.id)
  if (same) { same.qty++; return }
  lines.value.push({ key: `${p.id}-${Date.now()}`, product: p, unitId: unit.id, qty: 1 })
}
const unitOf = (l: Line) => l.product.units.find(u => u.id === l.unitId) ?? l.product.units[0]!
const lineTotal = (l: Line) => Math.round(l.qty * unitOf(l).price * 100) / 100
const subtotal = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0))
const zone = computed(() => zones.value.find(z => z.id === form.zoneId) ?? null)
const shipping = computed(() => !zone.value ? 0 : zone.value.freeShippingMin !== null && subtotal.value >= zone.value.freeShippingMin ? 0 : zone.value.rate)
const zoneOptions = computed(() => zones.value.map(z => ({ value: z.id, label: `${z.thana}, ${z.district} · ${money(z.rate)}${z.freeShippingMin ? ` (free from ${money(z.freeShippingMin)})` : ''}` })))

async function place() {
  errors.value = {}
  if (!normalizeBdPhone(form.phone)) errors.value.phone = 'Enter a valid mobile number'
  if (!form.customerId && !form.name.trim()) errors.value.name = 'Name of the new customer'
  if (!form.address.trim()) errors.value.address = 'Delivery address'
  if (!form.zoneId) errors.value.zone = 'Choose the delivery zone'
  if (!lines.value.length) errors.value.lines = 'Add at least one product'
  if (Object.keys(errors.value).length) return
  saving.value = true
  try {
    const order = await api.place({
      customerId: form.customerId, name: form.name.trim(), phone: normalizeBdPhone(form.phone)!, address: form.address.trim(),
      zoneId: form.zoneId!, note: form.note.trim(),
      lines: lines.value.map(l => ({ productId: l.product.id, unitId: l.unitId, qty: Number(l.qty) || 0 })),
    }, key)
    toast.success('Order placed', `${order.number} · COD ${money(order.codAmount)}`)
    emit('placed', order)
    open.value = false
  }
  catch (e: any) {
    toast.error('Order not placed', e?.message)
    key = crypto.randomUUID()
  }
  finally { saving.value = false }
}
</script>

<template>
  <UiModal v-model="open" title="New phone order" description="Phone or WhatsApp order, delivered and paid cash on delivery." size="xl" :dismissible="!saving">
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <UiInput v-model="form.phone" type="tel" label="Mobile" placeholder="01XXXXXXXXX" :error="errors.phone" :hint="known ?? 'An existing customer is matched by phone'" />
          <UiInput v-model="form.name" label="Customer name" :error="errors.name" :disabled="!!form.customerId" />
        </div>
        <UiInput v-model="form.address" label="Delivery address" placeholder="House, road, area" :error="errors.address" />
        <div class="grid gap-3 sm:grid-cols-2">
          <UiSelect v-model="form.zoneId" label="Delivery zone" :options="zoneOptions" placeholder="Choose zone" :error="errors.zone" />
          <UiInput v-model="form.note" label="Note (optional)" placeholder="e.g. call before coming" />
        </div>

        <div>
          <p class="label mb-2">Items</p>
          <UiProductPicker placeholder="Search a product to add…" @pick="add" />
          <p v-if="errors.lines" class="mt-1 text-xs text-rose-600">{{ errors.lines }}</p>
          <ul v-if="lines.length" class="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200">
            <li v-for="(l, i) in lines" :key="l.key" class="flex flex-wrap items-center gap-3 p-3">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">{{ l.product.name }} <span class="font-normal text-slate-500">{{ l.product.strength }}</span></p>
                <p class="text-xs text-slate-500 tabular">{{ num(l.product.stockBase) }} pcs in stock</p>
              </div>
              <select v-model="l.unitId" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm" aria-label="Unit">
                <option v-for="u in l.product.units" :key="u.id" :value="u.id">{{ u.label }} · {{ money(u.price) }}</option>
              </select>
              <input v-model.number="l.qty" type="number" min="1" class="h-9 w-20 rounded-lg border border-slate-200 px-2 text-right text-sm tabular" aria-label="Quantity">
              <span class="w-24 text-right text-sm font-semibold tabular">{{ money(lineTotal(l)) }}</span>
              <button class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
            </li>
          </ul>
        </div>
      </div>

      <div class="h-fit rounded-3xl bg-gradient-to-b from-brand-950 to-brand-900 p-5 text-white">
        <p class="flex items-center gap-2 text-xs font-semibold tracking-wider text-brand-200/70 uppercase"><PhoneCall class="size-3.5" />Cash on delivery</p>
        <p class="mt-1 font-display text-3xl font-extrabold tabular">{{ money(subtotal + shipping) }}</p>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between text-brand-100/80"><dt>Items</dt><dd class="tabular">{{ money(subtotal) }}</dd></div>
          <div class="flex justify-between text-brand-100/80"><dt class="flex items-center gap-1.5"><Truck class="size-3.5" />Delivery</dt><dd class="tabular">{{ zone ? (shipping ? money(shipping) : 'Free') : '—' }}</dd></div>
        </dl>
        <p class="mt-4 text-[11px] text-brand-200/60">Prices and delivery are confirmed by the server. Stock is taken when the order is confirmed.</p>
      </div>
    </div>

    <template #footer>
      <UiButton variant="ghost" :disabled="saving" @click="open = false">Cancel</UiButton>
      <UiButton variant="primary" :loading="saving" @click="place">Place order</UiButton>
    </template>
  </UiModal>
</template>
