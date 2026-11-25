<script setup lang="ts">
import { gsap } from 'gsap'
import { ShieldCheck, Lock, Save, Users, Check, Copy } from 'lucide-vue-next'
import type { PermissionGroup, Role, RowAction } from '~/types/domain'

definePageMeta({ title: 'Roles & permissions' })
const api = useAdminApi()
const toast = useToast()
const roles = ref<Role[]>([])
const groups = ref<PermissionGroup[]>([])
const selectedId = ref<number | null>(null)
const saving = ref(false)
const dirty = ref(false)
const matrix = ref<HTMLElement | null>(null)

onMounted(async () => {
  const r = await api.roles()
  roles.value = r.roles
  groups.value = r.groups
  selectedId.value = r.roles[2]?.id ?? r.roles[0]?.id ?? null
})
const role = computed(() => roles.value.find(r => r.id === selectedId.value) ?? null)
const locked = computed(() => !!role.value?.system)

watch(selectedId, async () => {
  dirty.value = false
  await nextTick()
  revealStagger(matrix.value?.querySelectorAll('[data-group]') ?? [], { y: 8, stagger: 0.04, duration: 0.35 })
})

const has = (key: string) => !!role.value?.permissions.includes(key)
function toggle(key: string, e?: Event) {
  if (!role.value || locked.value) return
  const p = role.value.permissions
  const i = p.indexOf(key)
  if (i >= 0) p.splice(i, 1)
  else p.push(key)
  dirty.value = true
  const box = (e?.currentTarget as HTMLElement | undefined)?.querySelector('[data-box]')
  if (box && !prefersReducedMotion()) gsap.fromTo(box, { scale: 0.7 }, { scale: 1, duration: 0.35, ease: 'back.out(3)' })
}
const groupState = (g: PermissionGroup) => {
  const n = g.permissions.filter(p => has(p.key)).length
  return n === 0 ? 'none' : n === g.permissions.length ? 'all' : 'some'
}
function toggleGroup(g: PermissionGroup) {
  if (!role.value || locked.value) return
  const all = groupState(g) === 'all'
  for (const p of g.permissions) {
    const i = role.value.permissions.indexOf(p.key)
    if (all && i >= 0) role.value.permissions.splice(i, 1)
    if (!all && i < 0) role.value.permissions.push(p.key)
  }
  dirty.value = true
}
const total = computed(() => groups.value.reduce((s, g) => s + g.permissions.length, 0))

async function save() {
  if (!role.value) return
  saving.value = true
  try {
    await api.saveRole(role.value)
    dirty.value = false
    toast.success('Role saved', `${role.value.name}: ${role.value.permissions.length} permissions · max discount ${role.value.maxDiscountPct}%`)
  }
  catch (e: any) { toast.error('Role not saved', e?.message) }
  finally { saving.value = false }
}

// ---- Row actions ----
const writes = useUserAdminApi()
const auth = useAuthStore()
const { ask } = useConfirm()
const canManage = computed(() => auth.can('roles.manage'))
const editOpen = ref(false)
const editTarget = ref<Role | null>(null)
const editForm = reactive({ name: '', description: '' })
function startEdit(r: Role) { editTarget.value = r; Object.assign(editForm, { name: r.name, description: r.description }); editOpen.value = true }
async function saveDetails() {
  const r = editTarget.value
  if (!r || !editForm.name.trim()) return
  if (roles.value.some(x => x.id !== r.id && x.name.toLowerCase() === editForm.name.trim().toLowerCase())) { toast.warning('A role with that name exists'); return }
  try {
    await writes.updateRoleDetails(r.id, { name: editForm.name.trim(), description: editForm.description })
    Object.assign(r, { name: editForm.name.trim(), description: editForm.description })
    editOpen.value = false
    toast.success('Role updated', r.name)
  }
  catch (e: any) { toast.error('Role not updated', e?.message) }
}
async function duplicate(r: Role) {
  const copy = { name: `${r.name} (copy)`, description: r.description, permissions: [...r.permissions], maxDiscountPct: r.maxDiscountPct }
  try {
    const { id } = await writes.createRole(copy, crypto.randomUUID())
    roles.value.push({ id, ...copy, users: 0 })
    selectedId.value = id
    toast.success('Role duplicated', `${copy.name}: adjust its permissions and save.`)
  }
  catch (e: any) { toast.error('Role not duplicated', e?.message) }
}
async function removeRole(r: Role) {
  const { ok } = await ask({ title: `Delete role ${r.name}?`, description: 'Only roles with no users can be deleted.', confirmLabel: 'Delete role' })
  if (!ok) return
  try {
    await writes.deleteRole(r.id)
    roles.value = roles.value.filter(x => x.id !== r.id)
    if (selectedId.value === r.id) selectedId.value = roles.value[0]?.id ?? null
    toast.success('Role deleted', r.name)
  }
  catch (e: any) { toast.error('Could not delete', e?.message) }
}
function roleActions(r: Role): RowAction[] {
  return [
    { label: 'Duplicate role', icon: Copy, onClick: () => duplicate(r), hidden: !canManage.value },
    { label: 'Users with this role', icon: Users, to: '/admin/users' },
  ]
}
</script>

<template>
  <div>
    <UiPageHeader title="Roles & permissions" description="The API checks these on every request. Hiding a menu is never the only protection.">
      <UiButton variant="primary" :icon="Save" :disabled="!dirty || locked" :loading="saving" @click="save">Save changes</UiButton>
    </UiPageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
      <div class="card h-fit overflow-hidden">
        <div
          v-for="r in roles" :key="r.id" role="button" tabindex="0"
          class="relative flex w-full cursor-pointer items-start gap-3 border-b border-slate-100 px-4 py-3.5 text-left transition-colors last:border-0"
          :class="r.id === selectedId ? 'bg-brand-50/70' : 'hover:bg-slate-50'"
          @click="selectedId = r.id" @keydown.enter="selectedId = r.id"
        >
          <span v-if="r.id === selectedId" class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-brand-500" />
          <span class="grid size-9 shrink-0 place-items-center rounded-xl" :class="r.id === selectedId ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500'"><ShieldCheck class="size-4" /></span>
          <span class="min-w-0">
            <span class="flex items-center gap-1.5 text-sm font-semibold">{{ r.name }}<Lock v-if="r.system" class="size-3 text-slate-400" /></span>
            <span class="block truncate text-xs text-slate-500">{{ r.description }}</span>
            <span class="mt-1 flex items-center gap-1 text-[11px] text-slate-400"><Users class="size-3" />{{ r.users }} users · {{ r.permissions.length }}/{{ total }} permissions</span>
            <UiRowActions
              class="mt-1.5 !justify-start" :label="r.name" :view="() => (selectedId = r.id)"
              :edit="canManage && !r.system ? () => startEdit(r) : undefined"
              :remove="canManage && !r.system && r.users === 0 ? () => removeRole(r) : undefined"
              :items="roleActions(r)"
            />
          </span>
        </div>
      </div>

      <div v-if="role" ref="matrix" class="space-y-4">
        <div class="card flex flex-wrap items-center gap-6 p-5">
          <div class="min-w-0 flex-1">
            <p class="font-display text-lg font-bold">{{ role.name }}</p>
            <p class="text-sm text-slate-500">{{ locked ? 'System role: always has every permission.' : role.description }}</p>
          </div>
          <label class="w-64">
            <span class="flex justify-between text-[13px] font-medium text-slate-700"><span>Max discount at counter</span><span class="font-bold text-brand-700 tabular">{{ role.maxDiscountPct }}%</span></span>
            <input v-model.number="role.maxDiscountPct" type="range" min="0" max="100" step="1" :disabled="locked" class="mt-2 w-full accent-brand-600" @input="dirty = true">
          </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div v-for="g in groups" :key="g.module" data-group class="card p-4">
            <button class="mb-2 flex w-full items-center justify-between" :disabled="locked" @click="toggleGroup(g)">
              <span class="label">{{ g.module }}</span>
              <span class="text-[11px] font-semibold" :class="groupState(g) === 'all' ? 'text-brand-700' : 'text-slate-400'">{{ groupState(g) === 'all' ? 'All' : groupState(g) === 'some' ? 'Some' : 'None' }}</span>
            </button>
            <button
              v-for="p in g.permissions" :key="p.key"
              class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-sm transition-colors disabled:cursor-not-allowed"
              :class="has(p.key) ? 'text-ink' : 'text-slate-500 hover:bg-slate-50'"
              :disabled="locked"
              @click="toggle(p.key, $event)"
            >
              <span data-box class="grid size-5 shrink-0 place-items-center rounded-md border transition-colors" :class="has(p.key) ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 bg-white'">
                <Check v-if="has(p.key)" class="size-3.5" :stroke-width="3" />
              </span>
              <span class="flex-1">{{ p.label }}</span>
              <code class="hidden text-[10.5px] text-slate-400 xl:inline">{{ p.key }}</code>
            </button>
          </div>
        </div>
      </div>
    </div>

    <UiModal v-model="editOpen" :title="editTarget ? `Edit role · ${editTarget.name}` : ''" description="Permissions are edited in the matrix on the right." size="sm">
      <div class="space-y-4">
        <UiInput v-model="editForm.name" label="Role name" />
        <UiTextarea v-model="editForm.description" label="Description" :rows="2" />
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="editOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :disabled="!editForm.name.trim()" @click="saveDetails">Save</UiButton>
      </template>
    </UiModal>
  </div>
</template>
