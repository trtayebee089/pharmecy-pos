<script setup lang="ts">
import { gsap } from 'gsap'
import { FileSpreadsheet, Download, Upload, CheckCircle2, AlertTriangle } from 'lucide-vue-next'

/**
 * Product CSV import: parsed and pre-validated in the browser for a preview;
 * the API re-validates every row and imports in one transaction.
 */
const open = defineModel<boolean>({ default: false })
const emit = defineEmits<{ imported: [count: number] }>()
const toast = useToast()
const api = useApi('catalog')

const HEADERS = ['sku', 'name', 'strength', 'generic', 'manufacturer', 'dosage_form', 'piece_price', 'pieces_per_strip', 'strips_per_box', 'reorder_level', 'requires_prescription']
interface Row { line: number; values: Record<string, string>; errors: string[] }
const rows = ref<Row[]>([])
const fileName = ref('')
const importing = ref(false)
const drop = ref<HTMLElement | null>(null)
const dragging = ref(false)

watch(open, o => { if (o) { rows.value = []; fileName.value = '' } })

function parseCsv(text: string): string[][] {
  const out: string[][] = []
  let row: string[] = [], cell = '', quoted = false
  for (let i = 0; i < text.length; i++) {
    const ch = text[i]!
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++ }
      else if (ch === '"') quoted = false
      else cell += ch
    }
    else if (ch === '"') quoted = true
    else if (ch === ',') { row.push(cell); cell = '' }
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++
      row.push(cell); cell = ''
      if (row.some(c => c.trim())) out.push(row)
      row = []
    }
    else cell += ch
  }
  row.push(cell)
  if (row.some(c => c.trim())) out.push(row)
  return out
}

async function load(file: File) {
  if (!file.name.toLowerCase().endsWith('.csv') || file.size > 5 * 1024 * 1024) { toast.error('Choose a CSV file under 5 MB'); return }
  fileName.value = file.name
  const [head, ...body] = parseCsv((await file.text()).replace(/^﻿/, ''))
  const cols = (head ?? []).map(h => h.trim().toLowerCase())
  const missing = ['sku', 'name', 'manufacturer', 'piece_price'].filter(h => !cols.includes(h))
  if (missing.length) { toast.error('Missing columns', missing.join(', ')); rows.value = []; return }
  const seen = new Set<string>()
  rows.value = body.map((cells, i) => {
    const values = Object.fromEntries(cols.map((c, j) => [c, (cells[j] ?? '').trim()]))
    const errors: string[] = []
    if (!values.sku) errors.push('SKU missing')
    else if (seen.has(values.sku)) errors.push('Duplicate SKU in file')
    seen.add(values.sku ?? '')
    if (!values.name) errors.push('Name missing')
    if (!(Number(values.piece_price) > 0)) errors.push('Piece price must be above 0')
    for (const k of ['pieces_per_strip', 'strips_per_box']) if (values[k] && !Number.isInteger(Number(values[k]))) errors.push(`${k} must be a whole number`)
    return { line: i + 2, values, errors }
  })
  await nextTick()
  revealStagger(document.querySelectorAll('[data-import-row]'), { y: 6, stagger: 0.015, duration: 0.25 })
}
function onFile(e: Event) { const f = (e.target as HTMLInputElement).files?.[0]; if (f) load(f) }
function onDrop(e: DragEvent) { dragging.value = false; const f = e.dataTransfer?.files?.[0]; if (f) load(f) }

const valid = computed(() => rows.value.filter(r => !r.errors.length))
const invalid = computed(() => rows.value.filter(r => r.errors.length))

function template() {
  downloadCsv('product-import-template.csv', HEADERS.map(h => ({ key: h, label: h })), [
    { sku: '1001', name: 'Napa', strength: '500 mg', generic: 'Paracetamol', manufacturer: 'Beximco Pharmaceuticals', dosage_form: 'Tablet', piece_price: 1.2, pieces_per_strip: 10, strips_per_box: 20, reorder_level: 200, requires_prescription: 'no' },
  ])
}

async function submit() {
  if (!valid.value.length) return
  importing.value = true
  try {
    if (api.useMocks) await mockDelay(900)
    else await api.post('/catalog/products/import', { rows: valid.value.map(r => r.values) }, crypto.randomUUID())
    toast.success('Import complete', `${valid.value.length} products imported${invalid.value.length ? `, ${invalid.value.length} skipped` : ''}.`)
    emit('imported', valid.value.length)
    open.value = false
  }
  catch (e: any) { toast.error('Import failed', e?.message) }
  finally { importing.value = false }
}
watch(dragging, d => drop.value && !prefersReducedMotion() && gsap.to(drop.value, { scale: d ? 1.015 : 1, duration: 0.25 }))
</script>

<template>
  <UiModal v-model="open" title="Import products" description="Upload a CSV. Existing SKUs are updated, new SKUs are created. Stock is never imported here: use a stock count." size="xl" :dismissible="!importing">
    <div
      ref="drop"
      class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-8 text-center transition-colors"
      :class="dragging ? 'border-brand-400 bg-brand-50' : 'border-slate-300 bg-slate-50/50'"
      @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop"
    >
      <FileSpreadsheet class="size-9 text-brand-600" />
      <p class="mt-2 text-sm font-semibold">{{ fileName || 'Drop a CSV here or choose a file' }}</p>
      <p class="text-xs text-slate-500">Columns: {{ HEADERS.join(', ') }}</p>
      <div class="mt-4 flex gap-2">
        <label class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold shadow-xs hover:bg-slate-50">
          <Upload class="size-4" />Choose file<input type="file" accept=".csv,text/csv" class="sr-only" @change="onFile">
        </label>
        <UiButton variant="ghost" :icon="Download" @click="template">Download template</UiButton>
      </div>
    </div>

    <template v-if="rows.length">
      <div class="mt-5 flex flex-wrap items-center gap-3 text-sm">
        <UiBadge tone="success" size="md"><CheckCircle2 class="size-3.5" />{{ valid.length }} ready</UiBadge>
        <UiBadge v-if="invalid.length" tone="danger" size="md"><AlertTriangle class="size-3.5" />{{ invalid.length }} with errors (skipped)</UiBadge>
      </div>
      <div class="mt-3 max-h-72 overflow-auto rounded-2xl border border-slate-200">
        <table class="w-full text-[13px]">
          <thead class="sticky top-0 bg-slate-50"><tr><th class="label px-3 py-2 text-left">Line</th><th class="label px-3 text-left">SKU</th><th class="label px-3 text-left">Name</th><th class="label px-3 text-left">Manufacturer</th><th class="label px-3 text-right">Piece ৳</th><th class="label px-3 text-left">Status</th></tr></thead>
          <tbody>
            <tr v-for="r in rows.slice(0, 200)" :key="r.line" data-import-row class="border-t border-slate-100" :class="r.errors.length ? 'bg-rose-50/50' : ''">
              <td class="px-3 py-2 text-slate-400 tabular">{{ r.line }}</td>
              <td class="px-3 font-mono">{{ r.values.sku }}</td>
              <td class="px-3">{{ r.values.name }} <span class="text-slate-500">{{ r.values.strength }}</span></td>
              <td class="px-3 text-slate-600">{{ r.values.manufacturer }}</td>
              <td class="px-3 text-right tabular">{{ r.values.piece_price }}</td>
              <td class="px-3"><span v-if="r.errors.length" class="text-xs font-semibold text-rose-600">{{ r.errors.join('; ') }}</span><span v-else class="text-xs font-semibold text-emerald-600">OK</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template #footer>
      <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
      <UiButton variant="primary" :icon="Upload" :loading="importing" :disabled="!valid.length" @click="submit">Import {{ valid.length || '' }} products</UiButton>
    </template>
  </UiModal>
</template>
