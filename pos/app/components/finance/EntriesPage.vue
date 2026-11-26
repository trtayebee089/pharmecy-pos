<script setup lang="ts">
import { Plus, Search, Paperclip, Download, Receipt, Tags, Upload, Printer, Copy } from 'lucide-vue-next'
import type { CrudColumn, FieldDef } from '~/types/domain'
import type { Account, FinanceCategory, FinanceEntry } from '~/types/finance'
const { brand } = useBrand()

/** Shared expenses / income page: posted entries (void-only) + category management. */
const props = defineProps<{ kind: 'expenses' | 'incomes'; permission: string }>()
const isExpense = computed(() => props.kind === 'expenses')
const noun = computed(() => isExpense.value ? 'Expense' : 'Income')
const api = useFinanceApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const categoryResource = api.categories(isExpense.value ? 'expense' : 'income')

const tab = ref<'entries' | 'categories'>('entries')
const rows = ref<FinanceEntry[]>([])
const accounts = ref<Account[]>([])
const categories = ref<FinanceCategory[]>([])
const loading = ref(true)
const q = ref('')
const status = ref<'posted' | 'void' | 'all'>('posted')
const category = ref('')
const tbody = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  try { [rows.value, accounts.value, categories.value] = await Promise.all([api.entries(props.kind), api.accounts(), categoryResource.all()]) }
  catch (e: any) { toast.error(`Could not load ${props.kind}`, e?.message) }
  finally { loading.value = false }
  await nextTick()
  revealStagger(tbody.value?.children ?? [], { y: 6, stagger: 0.015 })
}
onMounted(load)
watch(tab, t => t === 'entries' && load())

const filtered = computed(() => rows.value.filter(r =>
  (status.value === 'all' || r.status === status.value) && (!category.value || r.category === category.value)
  && `${r.number} ${r.category} ${r.account} ${r.note}`.toLowerCase().includes(q.value.toLowerCase())))
const monthTotal = computed(() => rows.value.filter(r => r.status === 'posted' && daysUntil(r.date) > -30).reduce((s, r) => s + r.amount, 0))
const topCategory = computed(() => {
  const m = new Map<string, number>()
  for (const r of rows.value) if (r.status === 'posted' && daysUntil(r.date) > -30) m.set(r.category, (m.get(r.category) ?? 0) + r.amount)
  return [...m.entries()].sort((a, b) => b[1] - a[1])[0]
})

// ---- New entry ----
const open = ref(false)
const form = reactive({ date: '', categoryId: null as number | null, accountId: null as number | null, amount: 0, note: '', file: null as File | null })
const errors = ref<Record<string, string>>({})
const posting = ref(false)
let idemKey = ''
const leafCategories = computed(() => categories.value.filter(c => c.isActive && !categories.value.some(x => x.parent === c.name)))
function start() {
  Object.assign(form, { date: new Date().toISOString().slice(0, 10), categoryId: null, accountId: accounts.value.find(a => a.isDefault)?.id ?? null, amount: 0, note: '', file: null })
  errors.value = {}
  idemKey = crypto.randomUUID()
  open.value = true
}
function onFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  if (f && (f.size > 5 * 1024 * 1024 || !/\.(pdf|jpe?g|png|webp)$/i.test(f.name))) { toast.warning('Attachment must be a PDF or image under 5 MB'); return }
  form.file = f
}
async function post() {
  errors.value = {}
  if (!form.categoryId) errors.value.category = 'Choose a category'
  if (!form.accountId) errors.value.account = 'Choose an account'
  if (!form.amount || form.amount <= 0) errors.value.amount = 'Amount must be above 0'
  if (Object.keys(errors.value).length) return
  posting.value = true
  try {
    const e = await api.postEntry(props.kind, {
      date: form.date, categoryId: form.categoryId!, accountId: form.accountId!, amount: Number(form.amount), note: form.note,
      category: categories.value.find(c => c.id === form.categoryId)?.name ?? '', account: accounts.value.find(a => a.id === form.accountId)?.name ?? '', hasAttachment: !!form.file,
    }, idemKey, form.file)
    toast.success(`${noun.value} posted`, `${e.number} · ${money(e.amount)}`)
    open.value = false
    load()
  }
  catch (err: any) { toast.error('Not posted', err?.message) }
  finally { posting.value = false }
}

async function voidEntry(r: FinanceEntry) {
  const { ok, reason } = await ask({ title: `Void ${r.number}?`, description: `${money(r.amount)} will be reversed on ${r.account}. The entry stays on record as void.`, confirmLabel: 'Void entry', requireReason: true })
  if (!ok) return
  try { await api.voidEntry(props.kind, r.id, reason); toast.success('Entry voided', r.number); load() }
  catch (e: any) { toast.error('Could not void', e?.message) }
}

// ---- Row actions ----
const viewing = ref<FinanceEntry | null>(null)
const viewOpen = ref(false)
const attachment = ref<string | null>(null)
function view(r: FinanceEntry) { viewing.value = r; attachment.value = null; viewOpen.value = true }
async function showAttachment(r: FinanceEntry) {
  view(r)
  try { attachment.value = await api.attachmentUrl(props.kind, r.id) }
  catch (e: any) { toast.error('Attachment not available', e?.message) }
}
function duplicate(r: FinanceEntry) {
  start()
  form.categoryId = categories.value.find(c => c.name === r.category)?.id ?? null
  form.accountId = accounts.value.find(a => a.name === r.account)?.id ?? form.accountId
  form.amount = r.amount
  form.note = r.note
}
function printVoucher(r: FinanceEntry) {
  const w = window.open('', '_blank', 'width=640,height=720')
  if (!w) { toast.warning('Allow pop-ups to print the voucher'); return }
  w.document.write(`<html><head><title>${r.number}</title><style>body{font:14px system-ui;padding:32px;color:#0f1b2d}h1{font-size:20px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:20px}td{padding:8px;border-bottom:1px solid #e2e8f0}td:first-child{color:#64748b;width:40%}.sig{margin-top:64px;display:flex;justify-content:space-between}.sig span{border-top:1px solid #000;padding-top:4px;width:40%;text-align:center}</style></head><body>
    <h1>${brand.value} · ${noun.value} voucher</h1><div>${r.number}${r.status === 'void' ? ' · VOID' : ''}</div>
    <table><tr><td>Date</td><td>${date(r.date)}</td></tr><tr><td>Category</td><td>${r.category}</td></tr><tr><td>${isExpense.value ? 'Paid from' : 'Received into'}</td><td>${r.account}</td></tr>
    <tr><td>Amount</td><td><b>${money(r.amount)}</b></td></tr><tr><td>Note</td><td>${r.note || '—'}</td></tr><tr><td>Entered by</td><td>${r.createdBy}</td></tr></table>
    <div class="sig"><span>Prepared by</span><span>Approved by</span></div></body></html>`)
  w.document.close()
  w.print()
}
function entryActions(r: FinanceEntry) {
  return [
    { label: 'Print voucher', icon: Printer, onClick: () => printVoucher(r) },
    { label: 'Duplicate', icon: Copy, onClick: () => duplicate(r), hidden: !auth.can(props.permission) },
    { label: 'View attachment', icon: Paperclip, onClick: () => showAttachment(r), hidden: !r.hasAttachment },
  ]
}

const csvColumns = [
  { key: 'number', label: 'Number' }, { key: 'date', label: 'Date' }, { key: 'category', label: 'Category' }, { key: 'account', label: 'Account' },
  { key: 'amount', label: 'Amount' }, { key: 'status', label: 'Status' }, { key: 'note', label: 'Note' }, { key: 'createdBy', label: 'By' },
]

const catColumns: CrudColumn[] = [
  { key: 'name', label: 'Category' }, { key: 'parent', label: 'Parent' },
  { key: 'entries', label: 'Entries', align: 'right', format: 'number' }, { key: 'isActive', label: 'Active', format: 'boolean' },
]
const catFields = computed<FieldDef[]>(() => [
  { key: 'name', label: 'Category name', type: 'text', required: true, span: 2 },
  { key: 'parent', label: 'Parent category', type: 'select', options: [{ value: '', label: 'None (top level)' }, ...categories.value.filter(c => !c.parent).map(c => ({ value: c.name, label: c.name }))] },
  { key: 'isActive', label: 'Active', type: 'switch' },
])
</script>

<template>
  <div>
    <div class="mb-4"><UiTabs v-model="tab" :tabs="[{ value: 'entries', label: isExpense ? 'Expenses' : 'Income', count: rows.length }, { value: 'categories', label: 'Categories', count: categories.length }]" /></div>

    <template v-if="tab === 'entries'">
      <UiPageHeader :title="isExpense ? 'Expenses' : 'Income'" :description="isExpense ? 'Operating costs paid from an account. Posted entries are never deleted; void them with a reason.' : 'Non-sales income received into an account. Void with a reason to reverse.'">
        <UiButton :icon="Download" :disabled="!filtered.length" @click="downloadCsv(`${kind}.csv`, csvColumns, filtered)">Export</UiButton>
        <UiButton v-if="auth.can(permission)" variant="primary" :icon="Plus" @click="start">New {{ noun.toLowerCase() }}</UiButton>
      </UiPageHeader>

      <div class="mb-5 grid gap-4 sm:grid-cols-2">
        <UiStatCard :label="`${isExpense ? 'Spent' : 'Received'} · last 30 days`" :value="monthTotal" :icon="Receipt" :tone="isExpense ? 'rose' : 'brand'" :loading="loading" />
        <div class="card p-5">
          <p class="label">Largest category · 30 days</p>
          <p class="mt-2 font-display text-xl font-extrabold">{{ topCategory?.[0] ?? '—' }}</p>
          <p class="text-sm text-slate-500 tabular">{{ topCategory ? money(topCategory[1]) : '' }}</p>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
          <div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Number, category, note…" /></div>
          <UiSegmented v-model="status" :options="[{ value: 'posted', label: 'Posted' }, { value: 'void', label: 'Void' }, { value: 'all', label: 'All' }]" />
          <UiSelect v-model="category" class="w-56" aria-label="Category filter" :options="[{ value: '', label: 'All categories' }, ...categories.map(c => ({ value: c.name, label: c.parent ? `${c.parent} › ${c.name}` : c.name }))]" />
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] text-sm">
            <thead><tr class="border-b border-slate-100 bg-slate-50/70"><th class="label h-10 px-4 text-left">Entry</th><th class="label text-left">Category</th><th class="label text-left">Account</th><th class="label text-right">Amount</th><th class="label pl-6 text-left">Status</th><th class="label text-left">By</th><th class="label px-4 text-right">Actions</th></tr></thead>
            <tbody v-if="loading"><tr v-for="i in 8" :key="i"><td colspan="7" class="px-4 py-2"><div class="skeleton h-8" /></td></tr></tbody>
            <tbody v-else ref="tbody">
              <tr v-for="r in filtered" :key="r.id" class="group border-b border-slate-50 hover:bg-brand-50/40">
                <td class="px-4 py-3">
                  <p class="flex items-center gap-1.5 font-mono text-[12.5px] font-semibold" :class="r.status === 'void' && 'text-slate-400 line-through'">{{ r.number }}<Paperclip v-if="r.hasAttachment" class="size-3.5 text-slate-400" aria-label="Has attachment" /></p>
                  <p class="text-xs text-slate-500">{{ date(r.date) }}</p>
                </td>
                <td class="font-medium">{{ r.category }}</td>
                <td class="text-slate-600">{{ r.account }}</td>
                <td class="text-right font-semibold tabular" :class="r.status === 'void' && 'text-slate-400 line-through'">{{ money(r.amount) }}</td>
                <td class="pl-6"><UiBadge :tone="r.status === 'posted' ? 'success' : 'neutral'" dot class="capitalize" :title="r.voidReason">{{ r.status }}</UiBadge></td>
                <td class="text-slate-500">{{ r.createdBy }}</td>
                <td class="px-3">
                  <UiRowActions
                    :label="r.number" :view="() => view(r)" remove-kind="void"
                    :remove="r.status === 'posted' && auth.can(permission) ? () => voidEntry(r) : undefined"
                    :items="entryActions(r)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
          <UiEmptyState v-if="!loading && !filtered.length" :icon="Receipt" :title="`No ${noun.toLowerCase()} entries`" description="Change the filters or post a new entry." />
        </div>
      </div>
    </template>

    <UiCrudPage
      v-else
      :title="`${noun} categories`" description="Group entries for reporting. One level of sub-categories is supported."
      :resource="categoryResource" :columns="catColumns" :fields="catFields" noun="Category" :create-permission="permission"
      :defaults="() => ({ name: '', parent: '', entries: 0, isActive: true })" :item-label="r => r.name"
      @saved="async () => { categories = await categoryResource.all() }"
    >
      <template #cell-name="{ row }"><span class="flex items-center gap-2 font-semibold"><Tags class="size-3.5 text-slate-400" />{{ row.name }}</span></template>
      <template #cell-parent="{ row }"><span class="text-slate-500">{{ row.parent || '—' }}</span></template>
    </UiCrudPage>

    <UiDrawer v-model="viewOpen" :title="viewing?.number" :description="`${noun} · ${viewing ? date(viewing.date) : ''}`" width="md">
      <dl v-if="viewing" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Category', viewing.category], [isExpense ? 'Paid from' : 'Received into', viewing.account], ['Amount', money(viewing.amount)], ['Status', viewing.status], ['Note', viewing.note || '—'], ['Entered by', viewing.createdBy]]" :key="k" data-stagger class="flex justify-between gap-6 py-3">
          <dt class="text-slate-500">{{ k }}</dt><dd class="text-right font-medium">{{ v }}</dd>
        </div>
        <div v-if="viewing.voidReason" class="py-3 text-rose-600">Void reason: {{ viewing.voidReason }}</div>
      </dl>
      <div v-if="viewing?.hasAttachment" class="mt-5">
        <p class="label mb-2">Attachment</p>
        <img v-if="attachment" :src="attachment" alt="Attached bill" class="w-full rounded-xl border border-slate-200">
        <UiButton v-else :icon="Paperclip" @click="viewing && showAttachment(viewing)">Load attachment</UiButton>
      </div>
      <template #footer>
        <UiButton :icon="Printer" @click="viewing && printVoucher(viewing)">Print voucher</UiButton>
        <UiButton variant="ghost" @click="viewOpen = false">Close</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="open" :title="`New ${noun.toLowerCase()}`" :dismissible="!posting">
      <div class="grid gap-4 sm:grid-cols-2">
        <UiSelect v-model="form.categoryId" label="Category *" :options="leafCategories.map(c => ({ value: c.id, label: c.parent ? `${c.parent} › ${c.name}` : c.name }))" placeholder="Choose category" :error="errors.category" />
        <UiSelect v-model="form.accountId" :label="isExpense ? 'Paid from *' : 'Received into *'" :options="accounts.filter(a => a.isActive).map(a => ({ value: a.id, label: a.name }))" placeholder="Choose account" :error="errors.account" />
        <UiInput v-model.number="form.amount" type="number" label="Amount (৳) *" inputmode="decimal" :error="errors.amount" />
        <UiInput v-model="form.date" type="date" label="Date" />
        <div class="sm:col-span-2"><UiTextarea v-model="form.note" label="Note" :placeholder="isExpense ? 'e.g. DESCO bill for October' : 'e.g. Quarterly display rent from Square'" :rows="2" /></div>
        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-600 transition hover:border-brand-400 hover:bg-brand-50/50 sm:col-span-2">
          <Upload class="size-4 text-brand-600" />
          <span class="flex-1 truncate">{{ form.file ? form.file.name : 'Attach bill or receipt (PDF or image, max 5 MB)' }}</span>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="hidden" @change="onFile">
        </label>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="posting" @click="post">Post {{ noun.toLowerCase() }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
