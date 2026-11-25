<script setup lang="ts">
import { FileText, Image as ImageIcon, Phone, MapPin, Save, RefreshCw } from 'lucide-vue-next'
import type { MedicineRequest } from '~/services/orders'

definePageMeta({ title: 'Medicine requests' })
const api = useMedicineRequestsApi()
const toast = useToast()

type Filter = 'new' | 'contacted' | 'ordered' | 'closed' | 'all'
const filter = ref<Filter>('new')
const rows = ref<MedicineRequest[]>([])
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const LABEL: Record<MedicineRequest['status'], string> = { new: 'New', contacted: 'Contacted', ordered: 'Ordered', closed: 'Closed' }
const TONE: Record<MedicineRequest['status'], string> = { new: 'bg-amber-100 text-amber-800', contacted: 'bg-sky-100 text-sky-800', ordered: 'bg-emerald-100 text-emerald-800', closed: 'bg-slate-100 text-slate-600' }
const tabs = computed(() => [
  ...(['new', 'contacted', 'ordered', 'closed'] as const).map(s => ({ value: s as Filter, label: LABEL[s], count: counts.value[s] ?? 0 })),
  { value: 'all' as Filter, label: 'All', count: Object.values(counts.value).reduce((a, b) => a + Number(b), 0) },
])

async function load() {
  loading.value = true
  try { const r = await api.list(filter.value); rows.value = r.requests; counts.value = r.counts }
  catch (e: any) { toast.error('Requests not loaded', e?.message) }
  finally { loading.value = false }
}
watch(filter, load)
onMounted(load)

// ---------------------------------------------------------------- detail
const open = ref(false)
const current = ref<MedicineRequest | null>(null)
const status = ref<MedicineRequest['status']>('new')
const note = ref('')
const saving = ref(false)
function show(r: MedicineRequest) {
  current.value = r
  status.value = r.status === 'new' ? 'contacted' : r.status
  note.value = r.staffNote ?? ''
  open.value = true
}
async function save() {
  if (!current.value) return
  saving.value = true
  try {
    current.value = await api.update(current.value.id, status.value, note.value || null)
    toast.success('Request updated', current.value.number)
    open.value = false
    await load()
  } catch (e: any) { toast.error('Not saved', e?.message) }
  finally { saving.value = false }
}
async function openFile(r: MedicineRequest, index: number) {
  // Open the tab first so the browser does not block it as a pop-up.
  const tab = window.open('', '_blank')
  try { const url = await api.fileUrl(r.id, index); if (tab) tab.location.href = url; else window.location.href = url }
  catch (e: any) { tab?.close(); toast.error('File not opened', e?.message) }
}
const size = (b: number) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
</script>

<template>
  <div>
    <UiPageHeader title="Medicine requests" description="Requests and prescriptions sent from the website. Call the customer, then place a phone order.">
      <UiButton :icon="RefreshCw" :loading="loading" @click="load">Refresh</UiButton>
    </UiPageHeader>
    <UiTabs v-model="filter" :tabs="tabs" class="mb-4" />

    <div v-if="loading && !rows.length" class="space-y-3"><div v-for="i in 3" :key="i" class="skeleton h-24" /></div>
    <UiEmptyState v-else-if="!rows.length" title="No requests here" description="New requests from the website appear in the New tab." />
    <ul v-else class="space-y-3">
      <li v-for="r in rows" :key="r.id">
        <button type="button" class="card flex w-full flex-wrap items-start gap-4 p-4 text-left transition hover:border-brand-300 hover:shadow-[var(--shadow-lift)]" @click="show(r)">
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2 font-semibold text-ink">
              {{ r.number }} · {{ r.name }}
              <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="TONE[r.status]">{{ LABEL[r.status] }}</span>
            </p>
            <p class="mt-0.5 text-xs text-slate-500 tabular">{{ r.phone }} · {{ dateTime(r.createdAt) }}</p>
            <p v-if="r.medicines" class="mt-2 line-clamp-2 whitespace-pre-line text-sm text-slate-700">{{ r.medicines }}</p>
          </div>
          <span v-if="r.files.length" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><FileText class="size-3.5" />{{ r.files.length }} file{{ r.files.length === 1 ? '' : 's' }}</span>
        </button>
      </li>
    </ul>

    <UiDrawer v-model="open" :title="current ? `${current.number} · ${current.name}` : ''" :description="current ? `Received ${dateTime(current.createdAt)}` : ''">
      <div v-if="current" class="space-y-5 text-sm">
        <div class="space-y-1.5 rounded-2xl bg-slate-50 p-4">
          <p class="flex items-center gap-2"><Phone class="size-4 text-slate-400" /><a :href="`tel:${current.phone}`" class="font-semibold text-brand-700 tabular">{{ current.phone }}</a></p>
          <p v-if="current.address" class="flex items-start gap-2"><MapPin class="mt-0.5 size-4 text-slate-400" />{{ current.address }}</p>
        </div>
        <div v-if="current.medicines">
          <p class="label mb-1.5">Medicines</p>
          <p class="whitespace-pre-line rounded-2xl border border-slate-100 p-3">{{ current.medicines }}</p>
        </div>
        <div v-if="current.files.length">
          <p class="label mb-1.5">Prescription files</p>
          <ul class="space-y-2">
            <li v-for="f in current.files" :key="f.index">
              <button type="button" class="flex w-full items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-left hover:border-brand-300 hover:bg-brand-50" @click="openFile(current!, f.index)">
                <component :is="f.mime.startsWith('image/') ? ImageIcon : FileText" class="size-5 text-slate-500" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ f.name }}</span>
                <span class="text-xs text-slate-500">{{ size(f.size) }}</span>
              </button>
            </li>
          </ul>
        </div>
        <div>
          <p class="label mb-2">Status</p>
          <UiSegmented v-model="status" :options="(['new', 'contacted', 'ordered', 'closed'] as const).map(s => ({ value: s, label: LABEL[s] }))" />
        </div>
        <UiTextarea v-model="note" label="Note for the team" :rows="3" placeholder="e.g. Called, placed phone order OO-…" />
        <p v-if="current.handledBy" class="text-xs text-slate-500">Last updated by {{ current.handledBy }} · {{ dateTime(current.handledAt!) }}</p>
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Close</UiButton>
        <UiButton variant="primary" :icon="Save" :loading="saving" @click="save">Save</UiButton>
      </template>
    </UiDrawer>
  </div>
</template>
