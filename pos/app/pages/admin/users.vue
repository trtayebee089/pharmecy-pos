<script setup lang="ts">
import { UserPlus, KeyRound, ShieldCheck, Search, Power, Shield } from 'lucide-vue-next'
import type { RowAction, StaffUser } from '~/types/domain'

definePageMeta({ title: 'Users' })
const api = useAdminApi()
const writes = useUserAdminApi()
const auth = useAuthStore()
const toast = useToast()
const { ask } = useConfirm()
const users = ref<StaffUser[]>([])
const roles = ref<string[]>([])
const loading = ref(true)
const q = ref('')
const list = ref<HTMLElement | null>(null)
const canManage = computed(() => auth.can('users.view'))
const isAdmin = computed(() => auth.user?.role === 'Admin' || auth.can('*'))

onMounted(async () => {
  const [u, r] = await Promise.all([api.users(), api.roles()])
  users.value = u
  roles.value = r.roles.map(x => x.name)
  loading.value = false
  await nextTick()
  revealStagger(list.value?.children ?? [], { stagger: 0.04 })
})
const filtered = computed(() => users.value.filter(u => `${u.name} ${u.username} ${u.role}`.toLowerCase().includes(q.value.toLowerCase())))

// ---- Create / edit ----
const open = ref(false)
const form = reactive({ id: undefined as number | undefined, name: '', username: '', email: '', role: 'Cashier', warehouses: ['Dhanmondi'] as string[] })
const formError = ref('')
const saving = ref(false)
let idemKey = ''
function startCreate() { Object.assign(form, { id: undefined, name: '', username: '', email: '', role: 'Cashier', warehouses: ['Dhanmondi'] }); formError.value = ''; idemKey = crypto.randomUUID(); open.value = true }
function startEdit(u: StaffUser) { Object.assign(form, { id: u.id, name: u.name, username: u.username, email: u.email, role: u.role, warehouses: [...u.warehouses] }); formError.value = ''; idemKey = crypto.randomUUID(); open.value = true }
async function save() {
  if (!form.name.trim() || !/^[a-z0-9._-]{3,}$/.test(form.username)) { formError.value = 'Name and a lowercase username (3+ characters) are required.'; return }
  if (!form.warehouses.length) { formError.value = 'Give access to at least one warehouse.'; return }
  if (['Admin', 'Owner'].includes(form.role) && !isAdmin.value) { formError.value = 'Only an Admin can grant the Admin or Owner role.'; return }
  saving.value = true
  try {
    const res = await writes.save({ ...form, warehouses: [...form.warehouses] }, idemKey)
    if (form.id) {
      const u = users.value.find(x => x.id === form.id)
      if (u) Object.assign(u, { name: form.name, username: form.username, email: form.email, role: form.role, warehouses: [...form.warehouses] })
      toast.success('User updated', form.name)
    }
    else {
      users.value.unshift({ id: res.id, name: form.name, username: form.username, email: form.email, role: form.role, warehouses: [...form.warehouses], isActive: true, lastLoginAt: null, mustChangePassword: true })
      toast.success('User created', `${form.name} must set a password at first sign-in.`)
    }
    open.value = false
  }
  catch (e: any) { formError.value = e?.message ?? 'Could not save' }
  finally { saving.value = false }
}

// ---- Row actions ----
const viewing = ref<StaffUser | null>(null)
const viewOpen = ref(false)
async function resetPassword(u: StaffUser) {
  const { ok } = await ask({ title: `Reset password for ${u.name}?`, description: 'A reset link is sent and they must set a new password at next sign-in. Their current sessions end.', confirmLabel: 'Send reset link', tone: 'primary' })
  if (!ok) return
  await writes.resetPassword(u.id)
  u.mustChangePassword = true
  toast.info('Password reset link issued', `${u.name} will set a new password at next sign-in.`)
}
async function toggleActive(u: StaffUser) {
  if (u.username === auth.user?.username) { toast.warning('You cannot disable your own account'); return }
  if (u.isActive) {
    const { ok } = await ask({ title: `Disable ${u.name}?`, description: 'They are signed out and cannot sign in. Their sales and history stay intact.', confirmLabel: 'Disable user' })
    if (!ok) return
  }
  await writes.setActive(u.id, !u.isActive)
  u.isActive = !u.isActive
  toast.success(u.isActive ? 'User enabled' : 'User disabled', u.name)
}
function userActions(u: StaffUser): RowAction[] {
  const protectedRole = ['Admin', 'Owner'].includes(u.role) && !isAdmin.value
  return [
    { label: 'Reset password', icon: KeyRound, onClick: () => resetPassword(u), hidden: !canManage.value || !u.isActive },
    { label: 'Change role', icon: Shield, onClick: () => startEdit(u), hidden: !canManage.value || protectedRole },
    { label: u.isActive ? 'Disable' : 'Enable', icon: Power, onClick: () => toggleActive(u), danger: u.isActive, hidden: !canManage.value || protectedRole },
  ]
}
const roleTone = (r: string) => r === 'Admin' ? 'violet' : r === 'Owner' ? 'brand' : r === 'Pharmacist' ? 'info' : 'neutral'
</script>

<template>
  <div>
    <UiPageHeader title="Users" description="Staff accounts. Roles grant permissions; warehouses limit which stock and sales a user sees. Users are disabled, never deleted.">
      <NuxtLink to="/admin/roles"><UiButton :icon="ShieldCheck">Roles & permissions</UiButton></NuxtLink>
      <UiButton v-if="canManage" variant="primary" :icon="UserPlus" @click="startCreate">Add user</UiButton>
    </UiPageHeader>

    <div class="card overflow-hidden">
      <div class="border-b border-slate-100 p-3"><div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Name, username, role…" /></div></div>
      <div class="hidden items-center gap-4 border-b border-slate-100 bg-slate-50/70 px-5 py-2 lg:flex">
        <span class="label w-10" /><span class="label min-w-48 flex-1">User</span><span class="label w-24">Role</span><span class="label w-40">Warehouses</span><span class="label w-36">Last sign-in</span><span class="label w-20">Status</span><span class="label w-32 text-right">Actions</span>
      </div>
      <div v-if="loading" class="space-y-2 p-4"><div v-for="i in 5" :key="i" class="skeleton h-14" /></div>
      <ul v-else ref="list" class="divide-y divide-slate-100">
        <li v-for="u in filtered" :key="u.id" class="flex flex-wrap items-center gap-4 px-5 py-3.5 transition-colors hover:bg-brand-50/40">
          <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-brand-300 to-brand-700 text-sm font-bold text-white" :class="!u.isActive && 'grayscale opacity-60'">{{ initials(u.name) }}</span>
          <div class="min-w-48 flex-1">
            <p class="flex items-center gap-2 text-sm font-semibold">{{ u.name }}<UiBadge v-if="u.mustChangePassword" tone="warning">Must change password</UiBadge></p>
            <p class="text-xs text-slate-500">@{{ u.username }} · {{ u.email }}</p>
          </div>
          <span class="w-24"><UiBadge :tone="roleTone(u.role)">{{ u.role }}</UiBadge></span>
          <span class="w-40 text-xs text-slate-500">{{ u.warehouses.join(', ') }}</span>
          <span class="w-36 text-xs text-slate-500">{{ u.lastLoginAt ? `Seen ${dateTime(u.lastLoginAt)}` : 'Never signed in' }}</span>
          <span class="w-20"><UiBadge :tone="u.isActive ? 'success' : 'neutral'" dot>{{ u.isActive ? 'Active' : 'Disabled' }}</UiBadge></span>
          <span class="w-32">
            <UiRowActions
              :label="u.name" :view="() => { viewing = u; viewOpen = true }"
              :edit="canManage && !(['Admin', 'Owner'].includes(u.role) && !isAdmin) ? () => startEdit(u) : undefined"
              :items="userActions(u)"
            />
          </span>
        </li>
      </ul>
    </div>

    <UiDrawer v-model="viewOpen" :title="viewing?.name" :description="viewing ? `@${viewing.username}` : ''" width="md">
      <dl v-if="viewing" class="divide-y divide-slate-100 text-sm">
        <div v-for="[k, v] in [['Email', viewing.email], ['Role', viewing.role], ['Warehouses', viewing.warehouses.join(', ')], ['Status', viewing.isActive ? 'Active' : 'Disabled'], ['Last sign-in', viewing.lastLoginAt ? dateTime(viewing.lastLoginAt) : 'Never'], ['Password', viewing.mustChangePassword ? 'Must be changed at next sign-in' : 'Set by user']]" :key="k" data-stagger class="flex justify-between gap-6 py-3"><dt class="text-slate-500">{{ k }}</dt><dd class="text-right font-medium">{{ v }}</dd></div>
      </dl>
      <template #footer>
        <NuxtLink to="/admin/roles"><UiButton :icon="ShieldCheck">Role permissions</UiButton></NuxtLink>
        <UiButton v-if="canManage && viewing" variant="primary" @click="viewOpen = false; startEdit(viewing!)">Edit user</UiButton>
      </template>
    </UiDrawer>

    <UiModal v-model="open" :title="form.id ? 'Edit user' : 'Add user'" description="The user sets their own password at first sign-in. No passwords are shared." :dismissible="!saving">
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput v-model="form.name" label="Full name" />
        <UiInput v-model="form.username" label="Username" placeholder="lowercase, no spaces" />
        <div class="sm:col-span-2"><UiInput v-model="form.email" label="Email" type="email" /></div>
        <UiSelect v-model="form.role" label="Role" :options="roles.map(r => ({ value: r, label: r }))" hint="Admin and Owner roles can only be granted by an Admin" />
        <div>
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Warehouses</p>
          <label v-for="w in ['Dhanmondi', 'Uttara']" :key="w" class="mr-4 inline-flex items-center gap-2 text-sm"><input v-model="form.warehouses" type="checkbox" :value="w" class="size-4 accent-brand-600">{{ w }}</label>
        </div>
      </div>
      <p v-if="formError" class="mt-3 text-sm font-medium text-rose-600">{{ formError }}</p>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="UserPlus" :loading="saving" @click="save">{{ form.id ? 'Save changes' : 'Create user' }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
