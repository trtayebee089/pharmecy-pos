<script setup lang="ts">
import { UserPlus, KeyRound, ShieldCheck, Search } from 'lucide-vue-next'
import type { StaffUser } from '~/types/domain'

definePageMeta({ title: 'Users' })
const api = useAdminApi()
const toast = useToast()
const users = ref<StaffUser[]>([])
const roles = ref<string[]>([])
const loading = ref(true)
const q = ref('')
const list = ref<HTMLElement | null>(null)

onMounted(async () => {
  const [u, r] = await Promise.all([api.users(), api.roles()])
  users.value = u
  roles.value = r.roles.map(x => x.name)
  loading.value = false
  await nextTick()
  revealStagger(list.value?.children ?? [], { stagger: 0.04 })
})
const filtered = computed(() => users.value.filter(u => `${u.name} ${u.username} ${u.role}`.toLowerCase().includes(q.value.toLowerCase())))

const open = ref(false)
const form = reactive({ name: '', username: '', email: '', role: 'Cashier', warehouses: ['Dhanmondi'] as string[] })
const formError = ref('')
function startCreate() { Object.assign(form, { name: '', username: '', email: '', role: 'Cashier', warehouses: ['Dhanmondi'] }); formError.value = ''; open.value = true }
function create() {
  if (!form.name.trim() || !/^[a-z0-9._-]{3,}$/.test(form.username)) { formError.value = 'Name and a lowercase username (3+ characters) are required.'; return }
  users.value.unshift({ id: Date.now(), ...form, warehouses: [...form.warehouses], isActive: true, lastLoginAt: null, mustChangePassword: true })
  open.value = false
  toast.success('User created', `${form.name} must set a password at first sign-in.`)
}
function resetPassword(u: StaffUser) { u.mustChangePassword = true; toast.info('Password reset link issued', `${u.name} will set a new password at next sign-in.`) }
const roleTone = (r: string) => r === 'Admin' ? 'violet' : r === 'Owner' ? 'brand' : r === 'Pharmacist' ? 'info' : 'neutral'
</script>

<template>
  <div>
    <UiPageHeader title="Users" description="Staff accounts. Roles grant permissions; warehouses limit which stock and sales a user sees.">
      <NuxtLink to="/admin/roles"><UiButton :icon="ShieldCheck">Roles & permissions</UiButton></NuxtLink>
      <UiButton variant="primary" :icon="UserPlus" @click="startCreate">Add user</UiButton>
    </UiPageHeader>

    <div class="card overflow-hidden">
      <div class="border-b border-slate-100 p-3"><div class="w-full sm:w-72"><UiInput v-model="q" :icon="Search" placeholder="Name, username, role…" /></div></div>
      <div v-if="loading" class="space-y-2 p-4"><div v-for="i in 5" :key="i" class="skeleton h-14" /></div>
      <ul v-else ref="list" class="divide-y divide-slate-100">
        <li v-for="u in filtered" :key="u.id" class="group flex flex-wrap items-center gap-4 px-5 py-3.5 transition-colors hover:bg-brand-50/40">
          <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-brand-300 to-brand-700 text-sm font-bold text-white" :class="!u.isActive && 'grayscale opacity-60'">{{ initials(u.name) }}</span>
          <div class="min-w-48 flex-1">
            <p class="flex items-center gap-2 text-sm font-semibold">{{ u.name }}<UiBadge v-if="u.mustChangePassword" tone="warning">Must change password</UiBadge></p>
            <p class="text-xs text-slate-500">@{{ u.username }} · {{ u.email }}</p>
          </div>
          <UiBadge :tone="roleTone(u.role)">{{ u.role }}</UiBadge>
          <span class="w-40 text-xs text-slate-500">{{ u.warehouses.join(', ') }}</span>
          <span class="w-36 text-xs text-slate-500">{{ u.lastLoginAt ? `Seen ${dateTime(u.lastLoginAt)}` : 'Never signed in' }}</span>
          <UiBadge :tone="u.isActive ? 'success' : 'neutral'" dot>{{ u.isActive ? 'Active' : 'Disabled' }}</UiBadge>
          <UiButton size="sm" variant="ghost" :icon="KeyRound" class="opacity-0 group-hover:opacity-100" @click="resetPassword(u)">Reset password</UiButton>
        </li>
      </ul>
    </div>

    <UiModal v-model="open" title="Add user" description="The user sets their own password at first sign-in. No passwords are shared.">
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
        <UiButton variant="primary" :icon="UserPlus" @click="create">Create user</UiButton>
      </template>
    </UiModal>
  </div>
</template>
