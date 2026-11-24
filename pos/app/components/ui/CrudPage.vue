<script setup lang="ts" generic="T extends { id: number } & Record<string, any>">
import { Plus, Pencil, Trash2, Download } from 'lucide-vue-next'
import type { CrudColumn, FieldDef } from '~/types/domain'
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
}>(), { deletable: true })

const emit = defineEmits<{ saved: [row: T] }>()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()

const segment = ref('all')
const { search, page, filters, result, loading, reload } = useList<T>(q => props.resource.list(q), props.segments ? { [props.segments.key]: '' } : {})
watch(segment, (v) => { if (props.segments) filters.value[props.segments.key] = v === 'all' ? '' : v })

const allColumns = computed<CrudColumn[]>(() => [...props.columns, { key: '__actions', label: '', align: 'right' }])
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
        <div class="flex justify-end gap-1 opacity-0 transition group-hover:opacity-100">
          <slot name="row-actions" :row="row" />
          <UiButton v-if="canEdit" size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${noun}`" @click="startEdit(row)" />
          <UiButton v-if="deletable && canEdit" size="sm" variant="ghost" :icon="Trash2" class="hover:!text-rose-600" :aria-label="`Delete ${noun}`" @click="remove(row)" />
        </div>
      </template>
    </UiDataTable>

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
