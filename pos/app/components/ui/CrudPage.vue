<script setup lang="ts" generic="T extends { id: number } & Record<string, any>">
import { Plus, Pencil, Download } from 'lucide-vue-next'
import type { CrudColumn, FieldDef, RowAction } from '~/types/domain'
import type { Resource } from '~/services/resource'

/**
 * Config-driven list + create/edit/delete page for master data.
 * Validation shown here is a convenience; the API validates again.
 */
const props = withDefaults(defineProps<{
  title: string
  description?: string
  resource: Resource<T>
  columns: CrudColumn[]
  fields: FieldDef[]
  defaults: () => Partial<T>
  noun: string
  createPermission?: string
  editPermission?: string
  deletable?: boolean
  segments?: { key: string; options: { value: string; label: string }[] }
  itemLabel?: (row: T) => string
  /** Extra actions for the row's More menu (Print, Duplicate, View history…) */
  rowActions?: (row: T) => RowAction[]
  /** Override the View action (e.g. a detail page); default opens a details drawer */
  viewTo?: (row: T) => string
}>(), { deletable: true })

const emit = defineEmits<{ saved: [row: T] }>()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()

const segment = ref('all')
const { search, page, filters, result, loading, reload } = useList<T>(q => props.resource.list(q), props.segments ? { [props.segments.key]: '' } : {})
watch(segment, (v) => { if (props.segments) filters.value[props.segments.key] = v === 'all' ? '' : v })

const allColumns = computed<CrudColumn[]>(() => [...props.columns, { key: '__actions', label: 'Actions', align: 'right', width: '1%' }])
const canCreate = computed(() => auth.can(props.createPermission))
const canEdit = computed(() => auth.can(props.editPermission ?? props.createPermission))

const open = ref(false)
const form = ref<Record<string, any>>({})
const errors = ref<Record<string, string>>({})
const saving = ref(false)

function startCreate() { form.value = { ...props.defaults() }; errors.value = {}; open.value = true }
function startEdit(row: T) { form.value = structuredClone(toRaw(row)); errors.value = {}; open.value = true }

async function save() {
  errors.value = {}
  for (const f of props.fields) {
    const v = form.value[f.key]
    if (f.required && (v === undefined || v === null || String(v).trim() === '')) errors.value[f.key] = `${f.label} is required`
    if ((f.type === 'money' || f.type === 'number') && v !== '' && v !== null && v !== undefined && Number(v) < 0) errors.value[f.key] = 'Cannot be negative'
  }
  if (Object.keys(errors.value).length) return
  for (const f of props.fields) if ((f.type === 'money' || f.type === 'number') && form.value[f.key] !== '' && form.value[f.key] != null) form.value[f.key] = Number(form.value[f.key])
  saving.value = true
  try {
    const saved = await props.resource.save(form.value as Partial<T>)
    toast.success(form.value.id ? `${props.noun} updated` : `${props.noun} created`, props.itemLabel?.(saved))
    open.value = false
    emit('saved', saved)
    reload()
  }
  catch (e: any) {
    if (e instanceof ApiError) for (const [k, v] of Object.entries(e.errors)) errors.value[k] = v[0] ?? ''
    toast.error('Could not save', e?.message)
  }
  finally { saving.value = false }
}

async function remove(row: T) {
  const { ok } = await ask({ title: `Delete this ${props.noun.toLowerCase()}?`, description: `${props.itemLabel?.(row) ?? ''} will be removed. Records already using it are not changed.`, confirmLabel: 'Delete' })
  if (!ok) return
  try { await props.resource.remove(row.id); toast.success(`${props.noun} deleted`); reload() }
  catch (e: any) { toast.error('Could not delete', e?.message) }
}

function exportCsv() {
  const rows = result.value?.data ?? []
  downloadCsv(`${props.title.toLowerCase().replace(/\s+/g, '-')}.csv`, props.columns, rows)
}
// ---- View drawer: every column and form field of the record ----
const viewOpen = ref(false)
const viewing = ref<T | null>(null)
function openView(row: T) { viewing.value = row; viewOpen.value = true }
const viewFields = computed(() => {
  const seen = new Set<string>()
  const out: { key: string; label: string; column?: CrudColumn }[] = []
  for (const c of props.columns) { seen.add(c.key); out.push({ key: c.key, label: c.label, column: c }) }
  for (const f of props.fields) if (!seen.has(f.key)) out.push({ key: f.key, label: f.label, column: { key: f.key, label: f.label, format: f.type === 'money' ? 'money' : f.type === 'switch' ? 'boolean' : f.type === 'date' ? 'date' : 'text' } })
  return out
})
defineExpose({ reload, startCreate, startEdit })
</script>

<template>
  <div>
    <UiPageHeader :title="title" :description="description">
      <slot name="header-actions" />
      <UiButton :icon="Download" @click="exportCsv">Export</UiButton>
      <UiButton v-if="canCreate" variant="primary" :icon="Plus" @click="startCreate">New {{ noun.toLowerCase() }}</UiButton>
    </UiPageHeader>

    <slot name="above" />

    <UiDataTable v-model:search="search" v-model:page="page" :columns="allColumns" :result="result" :loading="loading" row-key="id" :search-placeholder="`Search ${title.toLowerCase()}…`" :empty-title="`No ${title.toLowerCase()} yet`">
      <template v-if="segments" #filters>
        <UiSegmented v-model="segment" :options="[{ value: 'all', label: 'All' }, ...segments.options]" />
      </template>
      <template v-for="c in columns" :key="c.key" #[`cell-${c.key}`]="{ row, value }">
        <slot :name="`cell-${c.key}`" :row="row" :value="value"><UiCell :value="value" :column="c" /></slot>
      </template>
      <template #cell-__actions="{ row }">
        <UiRowActions
          :label="itemLabel?.(row) ?? noun"
          :view="viewTo ? viewTo(row) : () => openView(row)"
          :edit="canEdit ? () => startEdit(row) : undefined"
          :remove="deletable && canEdit ? () => remove(row) : undefined"
          :items="rowActions?.(row) ?? []"
        >
          <slot name="row-actions" :row="row" />
        </UiRowActions>
      </template>
    </UiDataTable>

    <UiDrawer v-model="viewOpen" :title="viewing ? (itemLabel?.(viewing) ?? noun) : noun" :description="`${noun} details`" width="md">
      <dl v-if="viewing" class="divide-y divide-slate-100">
        <div v-for="f in viewFields" :key="f.key" data-stagger class="flex items-start justify-between gap-6 py-3 text-sm">
          <dt class="text-slate-500">{{ f.label }}</dt>
          <dd class="text-right font-medium text-ink"><UiCell :value="(viewing as any)[f.key]" :column="f.column!" /></dd>
        </div>
      </dl>
      <template #footer>
        <UiButton variant="ghost" @click="viewOpen = false">Close</UiButton>
        <UiButton v-if="canEdit && viewing" variant="primary" :icon="Pencil" @click="viewOpen = false; startEdit(viewing!)">Edit</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="open" :title="form.id ? `Edit ${noun.toLowerCase()}` : `New ${noun.toLowerCase()}`" :dismissible="!saving">
      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
        <UiFormField v-for="f in fields" :key="f.key" :field="f" :model="form" :error="errors[f.key]" />
        <slot name="form-extra" :form="form" />
        <button type="submit" class="hidden" />
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="saving" @click="save">{{ form.id ? 'Save changes' : `Create ${noun.toLowerCase()}` }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
