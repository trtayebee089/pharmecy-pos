<script setup lang="ts">
import { Save, KeyRound, ShieldCheck, Store, Monitor, CheckCircle2, XCircle } from 'lucide-vue-next'
import type { AccountProfile } from '~/types/hr'

definePageMeta({ title: 'My profile' })
const api = useAccountApi()
const toast = useToast()
const profile = ref<AccountProfile | null>(null)
const form = reactive({ name: '', email: '', phone: '' })
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  profile.value = await api.profile()
  Object.assign(form, { name: profile.value.name, email: profile.value.email, phone: profile.value.phone })
  await nextTick()
  revealStagger(root.value?.querySelectorAll('[data-reveal]') ?? [], { stagger: 0.07 })
})
const dirty = computed(() => !!profile.value && (form.name !== profile.value.name || form.email !== profile.value.email || form.phone !== profile.value.phone))

async function save() {
  errors.value = {}
  if (form.name.trim().length < 2) errors.value.name = 'Name is required'
  if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errors.value.email = 'Enter a valid email'
  if (form.phone && !/^01[3-9]\d{8}$/.test(form.phone)) errors.value.phone = 'Use the 01XXXXXXXXX format'
  if (Object.keys(errors.value).length) return
  saving.value = true
  try {
    await api.saveProfile({ ...form })
    Object.assign(profile.value!, form)
    toast.success('Profile updated')
  }
  catch (e: any) { toast.error('Not saved', e?.message) }
  finally { saving.value = false }
}
</script>

<template>
  <div ref="root" class="mx-auto max-w-5xl">
    <UiPageHeader title="My profile" description="Your staff account. Role and warehouse access are managed by an administrator.">
      <NuxtLink to="/account/password"><UiButton :icon="KeyRound">Change password</UiButton></NuxtLink>
    </UiPageHeader>

    <div v-if="!profile" class="grid gap-5 lg:grid-cols-[320px_1fr]"><div class="skeleton h-72" /><div class="skeleton h-72" /></div>
    <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
      <div class="space-y-5">
        <div class="card overflow-hidden" data-reveal>
          <div class="relative bg-gradient-to-br from-brand-700 to-brand-950 px-6 pt-8 pb-14">
            <div class="pointer-events-none absolute -top-10 -right-10 size-40 rounded-full bg-brand-400/25 blur-2xl" />
          </div>
          <div class="-mt-10 px-6 pb-6">
            <span class="grid size-20 place-items-center rounded-3xl bg-gradient-to-br from-brand-300 to-brand-600 font-display text-2xl font-extrabold text-white shadow-[var(--shadow-glow)] ring-4 ring-white">{{ initials(profile.name) }}</span>
            <p class="mt-3 font-display text-xl font-extrabold">{{ profile.name }}</p>
            <p class="text-sm text-slate-500">@{{ profile.username }}</p>
            <div class="mt-4 space-y-2 text-sm">
              <p class="flex items-center gap-2"><ShieldCheck class="size-4 text-brand-600" /><UiBadge tone="violet">{{ profile.role }}</UiBadge></p>
              <p v-for="w in profile.warehouses" :key="w" class="flex items-center gap-2 text-slate-600"><Store class="size-4 text-slate-400" />{{ w }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-5">
        <UiSection title="Contact details" description="Used for password resets and notifications" :icon="ShieldCheck">
          <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
            <div class="sm:col-span-2"><UiInput v-model="form.name" label="Full name" :error="errors.name" autocomplete="name" /></div>
            <UiInput v-model="form.email" label="Email" type="email" :error="errors.email" autocomplete="email" />
            <UiInput v-model="form.phone" label="Mobile" type="tel" inputmode="tel" :error="errors.phone" placeholder="01XXXXXXXXX" autocomplete="tel" />
            <div class="flex justify-end sm:col-span-2"><UiButton type="submit" variant="primary" :icon="Save" :loading="saving" :disabled="!dirty">Save changes</UiButton></div>
          </form>
        </UiSection>

        <UiSection title="Recent sign-ins" description="Report anything you don't recognise to an administrator" :icon="Monitor">
          <ul class="divide-y divide-slate-100">
            <li v-for="(s, i) in profile.signIns" :key="i" class="flex items-center gap-3 py-3 text-sm">
              <component :is="s.ok ? CheckCircle2 : XCircle" class="size-5 shrink-0" :class="s.ok ? 'text-emerald-500' : 'text-rose-500'" />
              <div class="min-w-0 flex-1"><p class="font-medium">{{ s.device }}</p><p class="text-xs text-slate-500">{{ dateTime(s.at) }} · IP {{ s.ip }}</p></div>
              <UiBadge :tone="s.ok ? 'success' : 'danger'">{{ s.ok ? 'Signed in' : 'Failed attempt' }}</UiBadge>
            </li>
          </ul>
        </UiSection>
      </div>
    </div>
  </div>
</template>
