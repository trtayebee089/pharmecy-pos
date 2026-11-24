<script setup lang="ts">
import { gsap } from 'gsap'
import { KeyRound, Lock, Eye, EyeOff, Check, X, ArrowLeft, ShieldCheck } from 'lucide-vue-next'

definePageMeta({ title: 'Change password' })
const api = useAccountApi()
const toast = useToast()
const current = ref('')
const next = ref('')
const confirm = ref('')
const show = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const done = ref(false)
const card = ref<HTMLElement | null>(null)
const meter = ref<HTMLElement | null>(null)

const rules = computed(() => [
  { ok: next.value.length >= 8, label: 'At least 8 characters' },
  { ok: /[a-z]/i.test(next.value), label: 'Contains a letter' },
  { ok: /\d/.test(next.value), label: 'Contains a number' },
  { ok: !!next.value && next.value !== current.value, label: 'Different from the current password' },
])
const score = computed(() => {
  const v = next.value
  if (!v) return 0
  let s = 0
  if (v.length >= 8) s++
  if (v.length >= 12) s++
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++
  if (/\d/.test(v)) s++
  if (/[^a-z0-9]/i.test(v)) s++
  return Math.min(4, s)
})
const strength = computed(() => [
  { label: 'Too short', cls: 'bg-slate-300' }, { label: 'Weak', cls: 'bg-rose-500' }, { label: 'Fair', cls: 'bg-amber-500' },
  { label: 'Good', cls: 'bg-brand-500' }, { label: 'Strong', cls: 'bg-emerald-500' },
][score.value]!)
watch(score, (s) => {
  if (!meter.value) return
  const vars = { width: `${(s / 4) * 100}%` }
  prefersReducedMotion() ? gsap.set(meter.value, vars) : gsap.to(meter.value, { ...vars, duration: 0.4, ease: 'power2.out' })
})
const valid = computed(() => rules.value.every(r => r.ok) && next.value === confirm.value && !!current.value)

async function submit() {
  errors.value = {}
  if (!current.value) errors.value.current = 'Enter your current password'
  if (next.value !== confirm.value) errors.value.confirm = 'Passwords do not match'
  if (!rules.value.every(r => r.ok)) errors.value.next = 'The new password does not meet the rules'
  if (Object.keys(errors.value).length) { shake(card.value); return }
  saving.value = true
  try {
    await api.changePassword(current.value, next.value)
    current.value = next.value = confirm.value = ''
    done.value = true
    toast.success('Password changed', 'Other sessions were signed out.')
  }
  catch (e: any) {
    if (e instanceof ApiError && e.errors.current) errors.value.current = e.errors.current[0] ?? 'Incorrect password'
    else toast.error('Password not changed', e?.message)
    shake(card.value)
  }
  finally { saving.value = false }
}
onMounted(() => revealStagger(card.value?.querySelectorAll('[data-in]') ?? [], { stagger: 0.06 }))
</script>

<template>
  <div class="mx-auto max-w-lg">
    <div class="mb-6 flex items-center gap-3">
      <NuxtLink to="/account/profile"><UiButton variant="ghost" :icon="ArrowLeft" aria-label="Back to profile" /></NuxtLink>
      <div>
        <h1 class="font-display text-[26px] font-extrabold tracking-tight text-ink">Change password</h1>
        <p class="text-sm text-slate-500">Changing it signs you out on every other device.</p>
      </div>
    </div>

    <div ref="card" class="card p-6">
      <div v-if="done" class="flex items-start gap-3 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800" data-in>
        <ShieldCheck class="size-5 shrink-0" /><p>Your password was changed. Use the new password next time you sign in.</p>
      </div>
      <form v-else class="space-y-4" autocomplete="off" @submit.prevent="submit">
        <div data-in><UiInput v-model="current" label="Current password" :icon="Lock" :type="show ? 'text' : 'password'" autocomplete="current-password" :error="errors.current" /></div>
        <div data-in>
          <UiInput v-model="next" label="New password" :icon="KeyRound" :type="show ? 'text' : 'password'" autocomplete="new-password" :error="errors.next">
            <template #suffix>
              <button type="button" class="mr-2 rounded-lg p-1.5 text-slate-400 hover:text-slate-600" :aria-label="show ? 'Hide passwords' : 'Show passwords'" @click="show = !show">
                <component :is="show ? EyeOff : Eye" class="size-4" />
              </button>
            </template>
          </UiInput>
          <div class="mt-2.5 flex items-center gap-3">
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div ref="meter" class="h-full w-0 rounded-full transition-colors" :class="strength.cls" /></div>
            <span class="w-16 text-right text-xs font-semibold text-slate-600">{{ next ? strength.label : '' }}</span>
          </div>
        </div>
        <ul data-in class="grid gap-1.5 text-[13px] sm:grid-cols-2">
          <li v-for="r in rules" :key="r.label" class="flex items-center gap-1.5" :class="r.ok ? 'text-emerald-700' : 'text-slate-500'">
            <component :is="r.ok ? Check : X" class="size-3.5" />{{ r.label }}
          </li>
        </ul>
        <div data-in><UiInput v-model="confirm" label="Confirm new password" :icon="KeyRound" :type="show ? 'text' : 'password'" autocomplete="new-password" :error="errors.confirm" /></div>
        <div data-in class="pt-2"><UiButton type="submit" variant="primary" size="lg" block :loading="saving" :disabled="!valid">Update password</UiButton></div>
      </form>
    </div>
  </div>
</template>
