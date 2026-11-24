<script setup lang="ts">
import { gsap } from 'gsap'
import { ArrowRight, Cross, Eye, EyeOff, Lock, ShieldCheck, User } from 'lucide-vue-next'

definePageMeta({ layout: 'bare', title: 'Sign in' })

const auth = useAuthStore()
const route = useRoute()
const username = ref('')
const password = ref('')
const show = ref(false)
const loading = ref(false)
const error = ref('')
const form = ref<HTMLElement | null>(null)
const hero = ref<HTMLElement | null>(null)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(username.value.trim(), password.value)
    // Fade out, but never let the animation block sign-in (rAF is paused in hidden tabs).
    if (!prefersReducedMotion()) await Promise.race([gsap.to(form.value, { opacity: 0, y: -10, duration: 0.25 }).then(() => undefined), new Promise(r => setTimeout(r, 300))])
    await navigateTo(String(route.query.redirect ?? '/dashboard'))
  }
  catch (e: any) {
    error.value = e?.message ?? 'Sign in failed'
    shake(form.value)
  }
  finally { loading.value = false }
}

onMounted(() => {
  if (prefersReducedMotion()) return
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' } })
  tl.from('[data-hero-line]', { y: 40, opacity: 0, duration: 0.9, stagger: 0.12 })
    .from('[data-hero-chip]', { y: 16, opacity: 0, duration: 0.6, stagger: 0.08 }, '-=0.5')
    .from('[data-form-item]', { y: 18, opacity: 0, duration: 0.6, stagger: 0.07 }, 0.2)
  // Floating capsules drift
  hero.value?.querySelectorAll<HTMLElement>('[data-float]').forEach((el, i) => {
    gsap.to(el, { y: `+=${18 + i * 6}`, x: `+=${i % 2 ? 10 : -10}`, rotation: i % 2 ? 12 : -12, duration: 4 + i, ease: 'sine.inOut', yoyo: true, repeat: -1 })
  })
  // Parallax on pointer
  const xs = [...(hero.value?.querySelectorAll<HTMLElement>('[data-float]') ?? [])].map(el => gsap.quickTo(el, 'xPercent', { duration: 1.2, ease: 'power3.out' }))
  hero.value?.addEventListener('pointermove', (e) => {
    const r = hero.value!.getBoundingClientRect()
    const dx = (e.clientX - r.left) / r.width - 0.5
    xs.forEach((fn, i) => fn(dx * (8 + i * 4)))
  })
})
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
    <!-- Brand panel -->
    <section ref="hero" class="relative hidden overflow-hidden bg-brand-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
      <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgb(39_187_169/.35),transparent_45%),radial-gradient(circle_at_80%_70%,rgb(87_215_196/.18),transparent_40%)]" />
      <div class="grain absolute inset-0" />
      <!-- floating capsules -->
      <div data-float class="absolute top-[18%] right-[14%] h-16 w-36 rounded-full bg-gradient-to-r from-white/90 from-50% to-brand-400 to-50% shadow-2xl ring-1 ring-white/20" />
      <div data-float class="absolute top-[52%] right-[30%] h-10 w-24 rounded-full bg-gradient-to-r from-rose-300/90 from-50% to-white/80 to-50% opacity-80 blur-[1px]" />
      <div data-float class="absolute right-[8%] bottom-[20%] size-20 rounded-full bg-white/10 ring-1 ring-white/20 backdrop-blur-md" />
      <div data-float class="absolute top-[34%] left-[56%] size-6 rounded-full bg-brand-300/70" />

      <div class="relative flex items-center gap-3">
        <div class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-brand-300 to-brand-600 shadow-[0_10px_30px_-6px_rgb(39_187_169/.7)]">
          <Cross class="size-6 text-white" :stroke-width="2.6" />
        </div>
        <span class="font-display text-xl font-extrabold text-white">Red Pharma</span>
      </div>

      <div class="relative max-w-lg">
        <h1 class="font-display text-5xl leading-[1.05] font-extrabold tracking-tight text-white">
          <span data-hero-line class="block">Every batch.</span>
          <span data-hero-line class="block">Every expiry.</span>
          <span data-hero-line class="block bg-gradient-to-r from-brand-200 to-brand-400 bg-clip-text text-transparent">Under control.</span>
        </h1>
        <p data-hero-line class="mt-5 text-[15px] leading-relaxed text-brand-100/70">
          The pharmacy counter, inventory and accounts in one fast, keyboard-first workspace.
        </p>
        <div class="mt-8 flex flex-wrap gap-2">
          <span v-for="c in ['FEFO batch picking', 'Box · strip · piece', 'Barcode ready', 'Expiry alerts']" :key="c" data-hero-chip class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-medium text-brand-100 backdrop-blur-md">{{ c }}</span>
        </div>
      </div>

      <p class="relative text-xs text-brand-200/50">© {{ new Date().getFullYear() }} Red Pharma · Authorised staff only</p>
    </section>

    <!-- Form -->
    <section class="flex items-center justify-center px-6 py-12">
      <div ref="form" class="w-full max-w-sm">
        <div data-form-item class="mb-8 lg:hidden">
          <div class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-700"><Cross class="size-6 text-white" /></div>
        </div>
        <h2 data-form-item class="font-display text-3xl font-extrabold tracking-tight text-ink">Welcome back</h2>
        <p data-form-item class="mt-1.5 text-sm text-slate-500">Sign in with your staff account to open the counter.</p>

        <form class="mt-8 space-y-4" @submit.prevent="submit">
          <div data-form-item><UiInput v-model="username" label="Username or email" :icon="User" autocomplete="username" size="lg" /></div>
          <div data-form-item>
            <UiInput v-model="password" label="Password" :icon="Lock" :type="show ? 'text' : 'password'" autocomplete="current-password" size="lg" :error="error">
              <template #suffix>
                <button type="button" class="mr-2 rounded-lg p-1.5 text-slate-400 hover:text-slate-600" :aria-label="show ? 'Hide password' : 'Show password'" @click="show = !show">
                  <component :is="show ? EyeOff : Eye" class="size-4" />
                </button>
              </template>
            </UiInput>
          </div>
          <div data-form-item class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" class="size-4 rounded accent-brand-600">Keep me signed in</label>
            <a href="#" class="font-medium text-brand-700 hover:underline">Forgot password?</a>
          </div>
          <div data-form-item>
            <UiButton type="submit" variant="primary" size="lg" block :loading="loading" :icon-right="ArrowRight" magnetic>Sign in</UiButton>
          </div>
        </form>

        <p data-form-item class="mt-8 flex items-center gap-2 text-xs text-slate-500">
          <ShieldCheck class="size-4 text-brand-600" />Secured session · actions are logged for audit
        </p>
      </div>
    </section>
  </div>
</template>
