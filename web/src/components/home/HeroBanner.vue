<template>
  <section class="grid min-w-0 items-start xl:grid-cols-[330px_minmax(0,1fr)]">
    <!-- Desktop Sidebar -->
    <CategorySidebar
      :categories="categories"
      :loading="categoryLoading"
      :open="drawerOpen"
      @close="emit('close-sidebar')"
    />

    <!--
      Banner area (uploaded in the POS: Website › Banners): one wide main banner
      across the top (several rotate) and three equal tiles in a row beneath it.
      Without uploads it shows the built-in hero and tiles.
    -->
    <div class="min-w-0 space-y-3 p-3 sm:space-y-4 sm:p-4">
      <!-- Main banner -->
      <div
        class="group relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-900/[0.05] sm:rounded-3xl"
        @mouseenter="paused = true"
        @mouseleave="paused = false"
      >
        <template v-if="hero.length">
          <component
            :is="linkTag(slide)"
            v-for="(slide, index) in hero"
            v-show="index === active"
            :key="slide.id"
            v-bind="linkProps(slide)"
            class="relative block"
            :aria-hidden="index !== active"
          >
            <picture>
              <source v-if="slide.mobile_image" media="(max-width: 639px)" :srcset="slide.mobile_image" />
              <img
                :src="slide.image"
                :alt="slide.title"
                class="block w-full object-cover"
                :class="slide.mobile_image ? 'aspect-square sm:aspect-[3/1]' : 'aspect-[3/1]'"
                :loading="index === 0 ? 'eager' : 'lazy'"
                :fetchpriority="index === 0 ? 'high' : 'auto'"
              />
            </picture>
            <BannerCopy v-if="slide.subtitle || slide.button_text" :banner="slide" size="lg" />
          </component>

          <template v-if="hero.length > 1">
            <button
              type="button"
              class="absolute left-3 top-1/2 hidden h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-slate-700 shadow-md transition hover:bg-white group-hover:grid"
              aria-label="Previous banner"
              @click="go(active - 1)"
            >
              <ChevronLeft class="h-5 w-5" />
            </button>
            <button
              type="button"
              class="absolute right-3 top-1/2 hidden h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-slate-700 shadow-md transition hover:bg-white group-hover:grid"
              aria-label="Next banner"
              @click="go(active + 1)"
            >
              <ChevronRight class="h-5 w-5" />
            </button>
            <div class="absolute inset-x-0 bottom-3 flex justify-center gap-2">
              <button
                v-for="(slide, index) in hero"
                :key="slide.id"
                type="button"
                class="h-2 rounded-full transition-all"
                :class="index === active ? 'w-6 bg-white' : 'w-2 bg-white/60 hover:bg-white/90'"
                :aria-label="`Show banner ${index + 1}: ${slide.title}`"
                :aria-current="index === active"
                @click="go(index)"
              ></button>
            </div>
          </template>
        </template>

        <!-- Built-in main banner (no uploads yet) -->
        <div v-else class="relative overflow-hidden px-5 py-8 sm:aspect-[3/1] sm:px-10 sm:py-0">
          <span class="pointer-events-none absolute inset-0 bg-gradient-to-r from-white via-white to-accent-50/80"></span>
          <span class="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-accent-200/35 blur-3xl"></span>
          <div class="relative flex h-full items-center gap-8">
            <div class="min-w-0 flex-1">
              <div class="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 shadow-sm ring-1 ring-primary-100">
                <ShieldCheck class="h-4 w-4 text-primary-600" :stroke-width="2" />
                <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary-700 sm:text-[11px]">Licensed Online Pharmacy</span>
              </div>
              <h1 class="mt-4 font-display text-3xl font-extrabold leading-[1.05] text-slate-900 sm:text-4xl xl:text-5xl">
                Health & <span class="text-accent-600">Wellness</span>
              </h1>
              <p class="mt-3 max-w-xl text-sm leading-6 text-slate-600 sm:text-[15px]">
                Genuine medicines, trusted brands and prescription medicines, delivered quickly with pharmacist-backed service.
              </p>
              <div class="mt-5 flex flex-wrap gap-3">
                <RouterLink to="/categories" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-700">
                  Shop Now <ArrowRight class="h-4 w-4" />
                </RouterLink>
                <RouterLink to="/request-order" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-6 py-3 text-sm font-bold text-slate-800 transition hover:border-primary-300 hover:bg-primary-50">
                  <Upload class="h-4 w-4 text-primary-600" /> Upload Prescription
                </RouterLink>
              </div>
            </div>
            <img
              v-if="showcase"
              :src="showcase.image"
              :alt="showcase.name"
              class="hidden h-48 max-w-[34%] object-contain drop-shadow-xl lg:block xl:h-56"
              @error="$event.target.style.display = 'none'"
            />
          </div>
        </div>
      </div>

      <!-- Three tiles -->
      <div class="grid grid-cols-3 gap-2 sm:gap-4">
        <template v-if="tiles.length">
          <component
            :is="linkTag(tile)"
            v-for="tile in tiles"
            :key="tile.id"
            v-bind="linkProps(tile)"
            class="relative block overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-900/[0.05] transition hover:-translate-y-0.5 hover:shadow-md sm:rounded-2xl"
          >
            <img :src="tile.image" :alt="tile.title" class="block aspect-[16/10] w-full object-cover" loading="lazy" />
            <BannerCopy v-if="tile.subtitle || tile.button_text" :banner="tile" size="sm" />
          </component>
        </template>
        <template v-else>
          <RouterLink
            v-for="tile in builtInTiles"
            :key="tile.title"
            :to="tile.to"
            class="relative flex aspect-[16/10] flex-col justify-between overflow-hidden rounded-xl p-3 shadow-sm ring-1 ring-slate-900/[0.05] transition hover:-translate-y-0.5 hover:shadow-md sm:rounded-2xl sm:p-5"
            :class="tile.tone"
          >
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-white/80 sm:h-11 sm:w-11 sm:rounded-xl">
              <component :is="tile.icon" class="h-4 w-4 sm:h-5 sm:w-5" />
            </span>
            <span>
              <span class="block text-[12px] font-bold leading-tight text-slate-900 sm:text-base">{{ tile.title }}</span>
              <span class="mt-0.5 hidden text-xs text-slate-600 sm:block">{{ tile.text }}</span>
            </span>
          </RouterLink>
        </template>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight, BadgeCheck, ChevronLeft, ChevronRight, ShieldCheck, Truck, Upload } from 'lucide-vue-next'
import CategorySidebar from '../category/CategorySidebar.vue'
import { useSiteStore } from '../../stores/siteStore'

const props = defineProps({
  products: { type: Array, default: () => [] },
  loading: Boolean,
  categories: { type: Array, default: () => [] },
  categoryLoading: { type: Boolean, default: false },
  drawerOpen: { type: Boolean, default: false },
  suggestions: Array,
})

const emit = defineEmits(['close-sidebar'])
const site = useSiteStore()
const hero = computed(() => site.banners.hero || [])
const tiles = computed(() => site.banners.tiles || [])

/** A product with artwork for the built-in banner. */
const showcase = computed(() => props.products.find((product) => product.image) || null)

const builtInTiles = [
  { title: 'Fast delivery', text: 'Across our delivery zones', to: '/delivery-coverage', icon: Truck, tone: 'bg-gradient-to-br from-accent-50 to-white text-accent-700' },
  { title: 'Upload prescription', text: 'Our pharmacist prepares it', to: '/request-order', icon: Upload, tone: 'bg-gradient-to-br from-rose-50 to-white text-primary-700' },
  { title: '100% genuine', text: 'From licensed suppliers', to: '/categories', icon: BadgeCheck, tone: 'bg-gradient-to-br from-sky-50 to-white text-sky-700' },
]

/** Site paths open in the app; full links open in a new tab; no link = plain block. */
function linkTag(banner) {
  if (!banner.link_url) return 'div'
  return banner.link_url.startsWith('/') ? RouterLink : 'a'
}
function linkProps(banner) {
  if (!banner.link_url) return {}
  return banner.link_url.startsWith('/') ? { to: banner.link_url } : { href: banner.link_url, target: '_blank', rel: 'noopener' }
}

// ---------------------------------------------------------------- rotation
const active = ref(0)
const paused = ref(false)
let timer = null
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

function go(index) {
  const n = hero.value.length
  if (n) active.value = (index + n) % n
}
function start() {
  stop()
  if (hero.value.length > 1 && !reducedMotion) timer = setInterval(() => { if (!paused.value && !document.hidden) go(active.value + 1) }, 6000)
}
function stop() {
  if (timer) clearInterval(timer)
  timer = null
}
watch(() => hero.value.length, () => { active.value = 0; start() })
onMounted(start)
onBeforeUnmount(stop)

/** Optional text over a banner (only when the admin gave a subtitle or button text). */
const BannerCopy = defineComponent({
  props: { banner: { type: Object, required: true }, size: { type: String, default: 'lg' } },
  setup(p) {
    return () => {
      const lg = p.size === 'lg'
      return h('div', { class: `pointer-events-none absolute inset-0 flex items-end bg-gradient-to-t from-slate-950/60 via-slate-950/10 to-transparent ${lg ? 'p-5 sm:p-8' : 'p-2 sm:p-4'}` }, [
        h('div', { class: 'max-w-xl text-white' }, [
          h('p', { class: lg ? 'font-display text-xl font-extrabold sm:text-3xl' : 'text-[11px] font-bold leading-tight sm:text-base' }, p.banner.title),
          p.banner.subtitle ? h('p', { class: lg ? 'mt-1 text-sm text-white/85 sm:text-base' : 'mt-0.5 hidden text-xs text-white/85 sm:block' }, p.banner.subtitle) : null,
          p.banner.button_text ? h('span', { class: lg ? 'mt-3 inline-flex rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-900' : 'mt-2 hidden rounded-lg bg-white px-3 py-1 text-xs font-bold text-slate-900 sm:inline-flex' }, p.banner.button_text) : null,
        ]),
      ])
    }
  },
})
</script>
