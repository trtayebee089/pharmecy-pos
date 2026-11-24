<script setup lang="ts">
import { ArrowLeft, Save, Truck, Printer, Trash2, CheckCircle2 } from 'lucide-vue-next'
import type { DraftDetail } from '~/types/purchase-orders'
import type { Supplier } from '~/types/domain'

/** Legacy "Update Order Report": edit a draft, then create a pending purchase for a supplier. */
definePageMeta({ title: 'Draft order' })
const route = useRoute()
const api = usePurchaseOrdersApi()
const suppliersApi = useSuppliersApi()
const toast = useToast()
const { ask } = useConfirm()

const draft = ref<DraftDetail | null>(null)
const suppliers = ref<Supplier[]>([])
const supplierId = ref<number | null>(null)
const loading = ref(true)
const saving = ref(false)
const converting = ref(false)
const dirty = ref(false)
const done = ref<{ purchaseId: number; number: string } | null>(null)

onMounted(async () => {
  const [d, s] = await Promise.all([api.draft(Number(route.params.id)), suppliersApi.lookup()])
  draft.value = d
  suppliers.value = s
  // Suggest the supplier that distributes this company, if any
  supplierId.value = s.find(x => d && x.name.split(' ')[0] === d.company.split(' ')[0])?.id ?? null
  loading.value = false
})
const locked = computed(() => draft.value?.status === 'converted')

function removeRow(productId: number) {
  if (!draft.value) return
  draft.value.rows = draft.value.rows.filter(r => r.productId !== productId)
  dirty.value = true
}
async function update() {
  if (!draft.value) return
  saving.value = true
  await api.updateDraft(draft.value.id, draft.value.rows)
  saving.value = false
  dirty.value = false
  toast.success('Draft updated')
}
async function createPurchase() {
  if (!draft.value || !supplierId.value) return
  const supplier = suppliers.value.find(s => s.id === supplierId.value)!
  const lines = draft.value.rows.filter(r => r.orderQty > 0)
  if (!lines.length) { toast.warning('No quantities to order'); return }
  const { ok } = await ask({ title: `Create purchase order for ${supplier.name}?`, description: `${lines.length} lines, ${num(lines.reduce((s, r) => s + r.orderQty, 0))} pieces. The purchase is created as "Ordered"; stock is added only when you receive it with batch and expiry.`, confirmLabel: 'Create purchase order', tone: 'primary' })
  if (!ok) return
  converting.value = true
  try {
    if (dirty.value) await api.updateDraft(draft.value.id, draft.value.rows)
    done.value = await api.createPurchase(draft.value.id, supplier.id, supplier.name, crypto.randomUUID())
    draft.value.status = 'converted'
  }
  catch (e: any) { toast.error('Purchase not created', e?.message) }
  finally { converting.value = false }
}
const totals = computed(() => {
  const rows = draft.value?.rows.filter(r => r.orderQty > 0) ?? []
  return { lines: rows.length, pcs: rows.reduce((s, r) => s + r.orderQty, 0), value: rows.reduce((s, r) => s + r.orderQty * r.unitCost, 0) }
})
function printOrder() { window.print() }
</script>

<template>
  <div>
    <div v-if="loading" class="space-y-4"><div class="skeleton h-10 w-80" /><div class="skeleton h-96" /></div>
    <UiEmptyState v-else-if="!draft" title="Draft not found" />
    <template v-else>
      <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <NuxtLink to="/purchases/orders/drafts"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back" /></NuxtLink>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="font-display text-2xl font-extrabold tracking-tight">{{ draft.company }}</h1>
              <UiBadge :tone="locked ? 'success' : 'info'" dot>{{ locked ? `Ordered · ${draft.purchaseNumber}` : 'Draft' }}</UiBadge>
            </div>
            <p class="text-sm text-slate-500"><span class="font-mono">{{ draft.number }}</span> · {{ date(draft.date) }} · by {{ draft.createdBy }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <UiButton :icon="Printer" @click="printOrder">Print order</UiButton>
          <UiButton v-if="!locked" :icon="Save" :loading="saving" :disabled="!dirty" @click="update">Update draft</UiButton>
        </div>
      </div>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="card overflow-hidden">
          <PurchasingOrderTable :rows="draft.rows" :show-forecast="false" :removable="!locked" @remove="removeRow" @changed="dirty = true" />
        </div>
        <div class="space-y-5">
          <div class="card sticky top-20 overflow-hidden">
            <div class="bg-gradient-to-br from-brand-900 to-brand-950 p-5 text-white">
              <p class="text-[11px] font-semibold tracking-wider text-brand-200/70 uppercase">Estimated order value</p>
              <p class="mt-1 font-display text-3xl font-extrabold whitespace-nowrap tabular">{{ money(totals.value) }}</p>
              <p class="mt-1 text-sm text-brand-100/70">{{ totals.lines }} lines · {{ num(totals.pcs) }} pieces</p>
            </div>
            <div class="space-y-4 p-5">
              <template v-if="!locked">
                <UiSelect v-model="supplierId" label="Order from supplier" :options="suppliers.map(s => ({ value: s.id, label: s.name }))" placeholder="Choose supplier" hint="Costs are estimates from the last purchase; final prices come from the supplier invoice." />
                <UiButton variant="primary" size="lg" block :icon="Truck" :loading="converting" :disabled="!supplierId || !totals.lines" magnetic @click="createPurchase">Create purchase order</UiButton>
                <p class="text-center text-[11px] text-slate-400">Creates an "Ordered" purchase. Receive it later with batch and expiry to add stock.</p>
              </template>
              <div v-else class="text-sm text-slate-600">
                <p class="flex items-center gap-2 font-semibold text-emerald-700"><CheckCircle2 class="size-4" />Purchase order created</p>
                <p class="mt-1">This draft is locked. Edit or receive the purchase instead.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <UiModal :model-value="!!done" size="sm" :dismissible="false">
        <div class="py-4 text-center">
          <div class="mx-auto grid size-16 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 text-white shadow-[var(--shadow-glow)]"><CheckCircle2 class="size-8" /></div>
          <h3 class="mt-4 font-display text-xl font-extrabold">Purchase order created</h3>
          <p class="mt-1 font-mono text-sm text-slate-500">{{ done?.number }}</p>
          <p class="mt-3 text-sm text-slate-600">Status "Ordered". When the goods arrive, open it and receive with batch and expiry.</p>
          <div class="mt-6 flex justify-center gap-2">
            <NuxtLink to="/purchases/orders/drafts"><UiButton>Back to drafts</UiButton></NuxtLink>
            <NuxtLink :to="`/purchases/new?draft=${draft.id}&supplier=${supplierId}`"><UiButton variant="primary" :icon="Truck">Receive now</UiButton></NuxtLink>
          </div>
        </div>
      </UiModal>
    </template>
  </div>
</template>
