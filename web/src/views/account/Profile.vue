<template>
  <AccountShell>
    <h1 class="text-3xl font-black text-ink">Profile</h1>
    <form class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="save">
      <label class="text-sm font-bold text-slate-700">Name <input v-model.trim="user.name" required autocomplete="name" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
      <label class="text-sm font-bold text-slate-700">Phone <input :value="user.phone" readonly class="mt-2 w-full rounded-2xl bg-slate-100 px-4 py-3 text-slate-500" /><span class="mt-1 block text-xs font-normal text-slate-500">To change your phone number, contact the pharmacy.</span></label>
      <label class="text-sm font-bold text-slate-700">Email <input v-model.trim="user.email" type="email" autocomplete="email" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
      <label class="text-sm font-bold text-slate-700">Gender
        <select v-model="user.gender" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500"><option value="">Prefer not to say</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
      </label>
      <label class="md:col-span-2 text-sm font-bold text-slate-700">Default address <textarea v-model.trim="user.address" rows="3" autocomplete="street-address" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500"></textarea></label>
      <div class="md:col-span-2"><button :disabled="saving" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white disabled:opacity-50">{{ saving ? 'Saving…' : 'Save changes' }}</button></div>
    </form>

    <h2 class="mt-10 text-xl font-black text-ink">Change password</h2>
    <form class="mt-4 grid gap-4 md:grid-cols-3" @submit.prevent="changePassword">
      <label class="text-sm font-bold text-slate-700">Current password <input v-model="pw.current_password" type="password" required autocomplete="current-password" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
      <label class="text-sm font-bold text-slate-700">New password <input v-model="pw.password" type="password" required minlength="8" autocomplete="new-password" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
      <label class="text-sm font-bold text-slate-700">Repeat new password <input v-model="pw.password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
      <p class="md:col-span-3 text-xs text-slate-500">At least 8 characters with letters and numbers.</p>
      <div class="md:col-span-3"><button :disabled="changing" class="rounded-full bg-slate-800 px-6 py-3 font-bold text-white disabled:opacity-50">{{ changing ? 'Changing…' : 'Change password' }}</button></div>
    </form>
  </AccountShell>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import AccountShell from './partials/AccountShell.vue'
import { useAuthStore } from '../../stores/authStore'
import { useUiStore } from '../../stores/uiStore'
import { authService } from '../../services/authService'

const auth = useAuthStore()
const ui = useUiStore()
const saving = ref(false)
const changing = ref(false)
const user = reactive({ name: '', phone: '', email: '', gender: '', address: '' })
const pw = reactive({ current_password: '', password: '', password_confirmation: '' })

function fill(u = {}) {
  Object.assign(user, { name: u.name || '', phone: u.phone || u.phone_number || '', email: u.email || '', gender: u.gender || '', address: u.address || '' })
}

function firstError(e) {
  const first = Object.values(e.errors || {})[0]
  return (Array.isArray(first) ? first[0] : first) || e.message
}

async function save() {
  saving.value = true
  try {
    const fresh = await authService.updateProfile({ name: user.name, email: user.email || null, gender: user.gender || null, address: user.address || null })
    auth.persist({ token: auth.token, user: fresh })
    fill(fresh)
    ui.toast('Profile saved')
  } catch (e) {
    ui.toast(firstError(e), 'error')
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  changing.value = true
  try {
    await authService.changePassword(pw)
    Object.assign(pw, { current_password: '', password: '', password_confirmation: '' })
    ui.toast('Password changed')
  } catch (e) {
    ui.toast(firstError(e), 'error')
  } finally {
    changing.value = false
  }
}

fill(auth.user || {})
onMounted(async () => {
  try {
    const fresh = await authService.profile()
    auth.persist({ token: auth.token, user: fresh })
    fill(fresh)
  } catch { /* 401 signs out via the interceptor */ }
})
</script>
