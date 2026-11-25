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
const branches = computed(() => (auth.user?.warehouses ?? []).map(w => w.name))
const form = reactive({ id: undefined as number | undefined, name: '', username: '', email: '', role: 'Cashier', warehouses: [] as string[], password: '' })
const formError = ref('')
const saving = ref(false)
let idemKey = ''
function startCreate() { Object.assign(form, { id: undefined, name: '', username: '', email: '', role: 'Cashier', warehouses: branches.value.slice(0, 1), password: '' }); formError.value = ''; idemKey = crypto.randomUUID(); open.value = true }
function startEdit(u: StaffUser) { Object.assign(form, { id: u.id, name: u.name, username: u.username, email: u.email, role: u.role, warehouses: [...u.warehouses], password: '' }); formError.value = ''; idemKey = crypto.randomUUID(); open.value = true }
async function save() {
  if (!form.name.trim() || !/^[a-z0-9._-]{3,}$/.test(form.username)) { formError.value = 'Name and a lowercase username (3+ characters) are required.'; return }
  if (!form.warehouses.length) { formError.value = 'Give access to at least one warehouse.'; return }
  if (!form.id && form.password.length < 8) { formError.value = 'Set a temporary password of at least 8 characters.'; return }
  if (['Admin', 'Owner'].includes(form.role) && !isAdmin.value) { formError.value = 'Only an Admin can grant the Admin or Owner role.'; return }
  saving.value = true
  try {
    const res = await writes.save({ ...form, warehouses: [...form.warehouses], password: form.id ? undefined : form.password }, idemKey)
    if (form.id) {
      const u = users.value.find(x => x.id === form.id)
      if (u) Object.assign(u, { name: form.name, username: form.username, email: form.email, role: form.role, warehouses: [...form.warehouses] })
      toast.success('User updated', form.name)
    }
    else {
      users.value.unshift({ id: res.id, name: form.name, username: form.username, email: form.email, role: form.role, warehouses: [...form.warehouses], isActive: true, lastLoginAt: null, mustChangePassword: true })
      toast.success('User created', form.email ? `The temporary password was emailed to ${form.email}.` : `Hand ${form.name} the temporary password; they must change it at first sign-in.`)
    }
    open.value = false
  }
  catch (e: any) { formError.value = e?.message ?? 'Could not save' }
  finally { saving.value = false }
}

// ---- Row actions ----
const viewing = ref<StaffUser | null>(null)
const viewOpen = ref(false)
// Reset: the admin sets a temporary password (emailed when the user has an email); they must change it at next sign-in.
const resetTarget = ref<StaffUser | null>(null)
const resetPw = ref('')
const resetting = ref(false)
const resetOpen = computed({ get: () => !!resetTarget.value, set: (v) => { if (!v) resetTarget.value = null } })
function resetPassword(u: StaffUser) { resetTarget.value = u; resetPw.value = '' }
async function submitReset() {
  const u = resetTarget.value
  if (!u || resetPw.value.length < 8) return
  resetting.value = true
  try {
    await writes.resetPassword(u.id, resetPw.value)
    u.mustChangePassword = true
    toast.success('Password reset', u.email ? `The temporary password was emailed to ${u.email}.` : `Hand ${u.name} the temporary password.`)
    resetTarget.value = null
  }
  catch (e: any) { toast.error('Password not reset', e?.message) }
  finally { resetting.value = false }
}
async function toggleActive(u: StaffUser) {
  if (u.username === auth.user?.username) { toast.warning('You cannot disable your own account'); return }
  if (u.isActive) {
    const { ok } = await ask({ title: `Disable ${u.name}?`, description: 'They are signed out and cannot sign in. Their sales and history stay intact.', confirmLabel: 'Disable user' })
    if (!ok) return
  }
  try {
    await writes.setActive(u.id, !u.isActive)
    u.isActive = !u.isActive
    toast.success(u.isActive ? 'User enabled' : 'User disabled', u.name)
  }
  catch (e: any) { toast.error('Not changed', e?.message) }
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

    <UiModal v-model="open" :title="form.id ? 'Edit user' : 'Add user'" :description="form.id ? 'Change name, role or branches.' : 'Set a temporary password; it is emailed to the user, who must change it at first sign-in.'" :dismissible="!saving">
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput v-model="form.name" label="Full name" />
        <UiInput v-model="form.username" label="Username" placeholder="lowercase, no spaces" />
        <div class="sm:col-span-2"><UiInput v-model="form.email" label="Email" type="email" /></div>
        <UiSelect v-model="form.role" label="Role" :options="roles.map(r => ({ value: r, label: r }))" hint="Admin and Owner roles can only be granted by an Admin" />
        <div>
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Warehouses</p>
          <label v-for="w in branches" :key="w" class="mr-4 inline-flex items-center gap-2 text-sm"><input v-model="form.warehouses" type="checkbox" :value="w" class="size-4 accent-brand-600">{{ w }}</label>
        </div>
        <div v-if="!form.id" class="sm:col-span-2"><UiInput v-model="form.password" type="password" label="Temporary password" hint="At least 8 characters. Emailed to the user; they must change it at first sign-in." /></div>
      </div>
      <p v-if="formError" class="mt-3 text-sm font-medium text-rose-600">{{ formError }}</p>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="UserPlus" :loading="saving" @click="save">{{ form.id ? 'Save changes' : 'Create user' }}</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="resetOpen" :title="resetTarget ? `Reset password · ${resetTarget.name}` : ''" description="Their sessions end. They must choose a new password at next sign-in." size="sm" :dismissible="!resetting">
      <UiInput v-model="resetPw" type="password" label="Temporary password" :hint="resetTarget?.email ? `Emailed to ${resetTarget.email}` : 'No email on file: hand it over in person'" />
      <template #footer>
        <UiButton variant="ghost" :disabled="resetting" @click="resetOpen = false">Cancel</UiButton>
        <UiButton variant="primary" :loading="resetting" :disabled="resetPw.length < 8" @click="submitReset">Reset password</UiButton>
      </template>
    </UiModal>
  </div>
</template>
