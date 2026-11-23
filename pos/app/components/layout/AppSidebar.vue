<script setup lang="ts">
import { gsap } from 'gsap'
import { ChevronDown, ChevronsLeft, Cross } from 'lucide-vue-next'
import { navigation, type NavGroup } from '~/config/navigation'

const ui = useUiStore()
const auth = useAuthStore()
const route = useRoute()

/** Permission-filtered tree: groups with no visible children disappear. */
const groups = computed(() => navigation
  .map(g => ({ ...g, children: g.children?.filter(c => auth.can(c.permission)) }))
  .filter(g => (g.children ? g.children.length > 0 : auth.can(g.permission))))

const isActive = (to: string) => route.path === to || (to !== '/' && route.path.startsWith(`${to}/`))
const groupActive = (g: NavGroup) => g.to ? isActive(g.to) : !!g.children?.some(c => isActive(c.to))

const open = ref<Record<string, boolean>>({})
watch(() => route.path, () => {
  for (const g of groups.value) if (g.children && groupActive(g)) open.value[g.label] = true
}, { immediate: true })

function toggle(label: string) {
  if (ui.collapsed) { ui.collapsed = false; open.value[label] = true; return }
  open.value[label] = !open.value[label]
}

// Accordion animation
function onEnter(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.fromTo(el, { height: 0, opacity: 0 }, { height: 'auto', opacity: 1, duration: 0.38, ease: 'power3.out', onComplete: done })
  gsap.fromTo((el as HTMLElement).children, { x: -8, opacity: 0 }, { x: 0, opacity: 1, duration: 0.3, stagger: 0.03, delay: 0.05 })
}
function onLeave(el: Element, done: () => void) {
  if (prefersReducedMotion()) return done()
  gsap.to(el, { height: 0, opacity: 0, duration: 0.25, ease: 'power2.in', onComplete: done })
}

// Sliding active indicator
const nav = ref<HTMLElement | null>(null)
const indicator = ref<HTMLElement | null>(null)
function moveIndicator() {
  nextTick(() => {
    setTimeout(() => {
      const active = nav.value?.querySelector<HTMLElement>('[data-active="true"]')
      if (!indicator.value) return
      if (!active || active.offsetParent === null) { gsap.to(indicator.value, { opacity: 0, duration: 0.15 }); return }
      const navTop = nav.value!.getBoundingClientRect().top - nav.value!.scrollTop
      const r = active.getBoundingClientRect()
      const vars = { y: r.top - navTop, height: r.height, opacity: 1 }
      prefersReducedMotion() ? gsap.set(indicator.value, vars) : gsap.to(indicator.value, { ...vars, duration: 0.5, ease: 'expo.out' })
    }, 60)
  })
}
watch(() => [route.path, ui.collapsed, JSON.stringify(open.value)], moveIndicator)

// Collapse / expand width tween
const aside = ref<HTMLElement | null>(null)
watch(() => ui.collapsed, (c) => {
  if (!aside.value) return
  const w = c && window.innerWidth >= 1024 ? 76 : 272
  prefersReducedMotion() ? gsap.set(aside.value, { width: w }) : gsap.to(aside.value, { width: w, duration: 0.45, ease: 'expo.inOut' })
})
onMounted(() => {
  if (aside.value) gsap.set(aside.value, { width: ui.collapsed && window.innerWidth >= 1024 ? 76 : 272 })
  revealStagger(nav.value?.querySelectorAll('[data-group]') ?? [], { y: 6, stagger: 0.025, delay: 0.1 })
  moveIndicator()
})

const navigateMobile = () => { ui.mobileNav = false }
</script>

<template>
  <aside
    ref="aside"
    class="fixed inset-y-0 left-0 z-50 flex flex-col overflow-hidden border-r border-white/5 bg-brand-950 text-brand-100 transition-transform duration-300 lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
    :class="ui.mobileNav ? 'translate-x-0' : '-translate-x-full'"
  >
    <!-- ambient glow + grain -->
    <div class="pointer-events-none absolute -top-24 -left-20 size-72 rounded-full bg-brand-500/25 blur-3xl" />
    <div class="pointer-events-none absolute right-[-60px] bottom-10 size-56 rounded-full bg-teal-300/10 blur-3xl" />
    <div class="grain pointer-events-none absolute inset-0" />

    <div class="relative flex h-16 shrink-0 items-center gap-3 px-5">
      <div class="relative grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-300 to-brand-600 shadow-[0_6px_20px_-4px_rgb(39_187_169/.6)]">
        <Cross class="size-5 text-white" :stroke-width="2.6" />
      </div>
      <div class="min-w-0 transition-opacity duration-200" :class="ui.collapsed && 'lg:pointer-events-none lg:opacity-0'">
        <p class="font-display text-[15px] leading-tight font-extrabold tracking-tight text-white">Red Pharma</p>
        <p class="text-[11px] font-medium text-brand-300/80">Pharmacy POS</p>
      </div>
    </div>

    <nav ref="nav" class="relative flex-1 overflow-x-hidden overflow-y-auto px-3 pb-4" @scroll="moveIndicator">
      <span ref="indicator" class="pointer-events-none absolute top-0 right-3 left-3 rounded-xl bg-gradient-to-r from-brand-500/35 to-brand-400/10 opacity-0 ring-1 ring-brand-300/20 ring-inset">
        <span class="absolute top-1/2 -left-3 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand-300 shadow-[0_0_12px_rgb(87_215_196)]" />
      </span>

      <div v-for="g in groups" :key="g.label" data-group class="mb-0.5">
        <!-- single link -->
        <NuxtLink
          v-if="g.to" :to="g.to" :data-active="isActive(g.to)" :title="ui.collapsed ? g.label : undefined"
          class="relative flex h-10 items-center gap-3 rounded-xl px-3 text-[13.5px] font-medium transition-colors"
          :class="isActive(g.to) ? 'text-white' : 'text-brand-100/70 hover:bg-white/5 hover:text-white'"
          @click="navigateMobile"
        >
          <component :is="g.icon" class="size-[18px] shrink-0" />
          <span class="truncate transition-opacity" :class="ui.collapsed && 'lg:opacity-0'">{{ g.label }}</span>
        </NuxtLink>

        <!-- group -->
        <template v-else>
          <button
            class="relative flex h-10 w-full items-center gap-3 rounded-xl px-3 text-[13.5px] font-medium transition-colors"
            :class="groupActive(g) ? 'text-white' : 'text-brand-100/70 hover:bg-white/5 hover:text-white'"
            :data-active="ui.collapsed && groupActive(g)"
            :title="ui.collapsed ? g.label : undefined"
            :aria-expanded="!!open[g.label]"
            @click="toggle(g.label)"
          >
            <component :is="g.icon" class="size-[18px] shrink-0" />
            <span class="flex-1 truncate text-left transition-opacity" :class="ui.collapsed && 'lg:opacity-0'">{{ g.label }}</span>
            <ChevronDown class="size-4 shrink-0 transition-transform duration-300" :class="[open[g.label] && 'rotate-180', ui.collapsed && 'lg:opacity-0']" />
          </button>
          <Transition :css="false" @enter="onEnter" @leave="onLeave">
            <div v-if="open[g.label] && !ui.collapsed" class="overflow-hidden">
              <div class="relative ml-[21px] border-l border-white/10 py-1 pl-3">
                <NuxtLink
                  v-for="c in g.children" :key="c.to" :to="c.to" :data-active="isActive(c.to)"
                  class="relative flex h-9 items-center gap-2 rounded-lg px-3 text-[13px] transition-colors"
                  :class="isActive(c.to) ? 'font-semibold text-white' : 'text-brand-100/60 hover:text-white'"
                  @click="navigateMobile"
                >
                  <span class="flex-1 truncate">{{ c.label }}</span>
                  <span v-if="c.badge && ui.badges[c.badge]" class="rounded-md bg-amber-400/90 px-1.5 text-[10px] font-bold text-amber-950 tabular">{{ ui.badges[c.badge] }}</span>
                  <span v-else-if="!c.ready" class="rounded-md bg-white/5 px-1.5 text-[9.5px] font-semibold tracking-wide text-brand-200/50 uppercase">{{ c.phase }}</span>
                </NuxtLink>
              </div>
            </div>
          </Transition>
        </template>
      </div>
    </nav>

    <div class="relative hidden shrink-0 border-t border-white/5 p-3 lg:block">
      <button class="flex h-10 w-full items-center gap-3 rounded-xl px-3 text-[13px] text-brand-100/60 transition hover:bg-white/5 hover:text-white" @click="ui.collapsed = !ui.collapsed">
        <ChevronsLeft class="size-[18px] shrink-0 transition-transform duration-500" :class="ui.collapsed && 'rotate-180'" />
        <span class="truncate transition-opacity" :class="ui.collapsed && 'opacity-0'">Collapse sidebar</span>
      </button>
    </div>
  </aside>
  <div v-if="ui.mobileNav" class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-sm lg:hidden" @click="ui.mobileNav = false" />
</template>
