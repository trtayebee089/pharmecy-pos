<script setup lang="ts">
import { Bell, ChevronDown, LogOut, Menu, Search, Store, UserRound, KeyRound, ScanBarcode } from 'lucide-vue-next'
import { flatNav } from '~/config/navigation'

const ui = useUiStore()
const auth = useAuthStore()
const route = useRoute()

const crumbs = computed(() => {
  const item = flatNav().find(n => n.to === route.path)
  if (item) return item.group === item.label ? [item.label] : [item.group, item.label]
  return [String(route.meta.title ?? '')].filter(Boolean)
})

const userMenu = ref(false)
const whMenu = ref(false)
const bell = ref(false)
const menuRef = ref<HTMLElement | null>(null)
const whRef = ref<HTMLElement | null>(null)
const bellRef = ref<HTMLElement | null>(null)
onClickOutside(menuRef, () => (userMenu.value = false))
onClickOutside(whRef, () => (whMenu.value = false))
onClickOutside(bellRef, () => (bell.value = false))

const notifications = computed(() => [
  ui.badges.nearExpiry && { tone: 'warning', title: `${ui.badges.nearExpiry} batches expire within 90 days`, to: '/inventory/batches' },
  ui.badges.lowStock && { tone: 'danger', title: `${ui.badges.lowStock} products at or below reorder level`, to: '/products' },
].filter(Boolean) as { tone: string; title: string; to: string }[])
</script>

<template>
  <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-surface/80 px-4 backdrop-blur-xl sm:px-6">
    <button class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-200/60 lg:hidden" aria-label="Open menu" @click="ui.mobileNav = true">
      <Menu class="size-5" />
    </button>

    <!-- Left: page title (breadcrumb) and search. Both may shrink; the right-hand tools never do. -->
    <nav class="hidden max-w-[16rem] min-w-0 shrink items-center gap-2 text-sm lg:flex xl:max-w-xs" aria-label="Breadcrumb">
      <template v-for="(c, i) in crumbs" :key="c">
        <span v-if="i" class="shrink-0 text-slate-300">/</span>
        <span :class="i === crumbs.length - 1 ? 'font-semibold text-ink' : 'shrink-[2] text-slate-500'" class="min-w-0 truncate">{{ c }}</span>
      </template>
    </nav>

    <button
      class="group flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-400 shadow-xs transition hover:border-slate-300 sm:max-w-md lg:ml-4"
      aria-label="Search pages, products, customers"
      @click="ui.commandOpen = true"
    >
      <Search class="size-4 shrink-0 transition-colors group-hover:text-brand-600" />
      <span class="min-w-0 flex-1 truncate text-left">Search pages, products, customers…</span>
      <UiKbd class="hidden shrink-0 xl:inline-flex">Ctrl K</UiKbd>
    </button>

    <!-- Right: always against the right edge -->
    <div class="ml-auto flex shrink-0 items-center gap-1.5">
      <NuxtLink v-if="auth.can('pos.sell')" to="/pos" class="hidden md:block" aria-label="New sale">
        <UiButton variant="primary" :icon="ScanBarcode" magnetic><span class="hidden xl:inline">New sale</span></UiButton>
      </NuxtLink>

      <!-- Warehouse switcher -->
      <div ref="whRef" class="relative hidden md:block">
        <button class="flex h-10 items-center gap-2 rounded-xl px-3 text-sm font-medium text-slate-600 hover:bg-slate-200/50" :title="auth.warehouse?.name" @click="whMenu = !whMenu">
          <Store class="size-4 shrink-0 text-brand-600" />
          <span class="hidden max-w-32 truncate lg:inline 2xl:max-w-48">{{ auth.warehouse?.name }}</span>
          <ChevronDown class="size-4 shrink-0 text-slate-400" />
        </button>
        <UiPopover :open="whMenu" class="right-0 w-64">
          <p class="label px-3 pt-2 pb-1">Warehouse</p>
          <button
            v-for="w in auth.user?.warehouses" :key="w.id"
            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50"
            :class="w.id === auth.warehouseId && 'font-semibold text-brand-700'"
            @click="auth.warehouseId = w.id; whMenu = false"
          >
            {{ w.name }}<span v-if="w.id === auth.warehouseId" class="size-2 rounded-full bg-brand-500" />
          </button>
        </UiPopover>
      </div>

      <!-- Notifications -->
      <div ref="bellRef" class="relative">
        <button class="relative grid size-10 place-items-center rounded-xl text-slate-600 hover:bg-slate-200/50" aria-label="Notifications" @click="bell = !bell">
          <Bell class="size-[19px]" />
          <span v-if="notifications.length" class="absolute top-2 right-2 flex size-2.5">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-rose-400 opacity-75" />
            <span class="relative inline-flex size-2.5 rounded-full bg-rose-500 ring-2 ring-surface" />
          </span>
        </button>
        <UiPopover :open="bell" class="right-0 w-80">
          <p class="label px-3 pt-2 pb-1">Alerts</p>
          <NuxtLink v-for="n in notifications" :key="n.title" :to="n.to" class="flex items-start gap-2.5 rounded-lg px-3 py-2.5 text-sm hover:bg-slate-50" @click="bell = false">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="n.tone === 'warning' ? 'bg-amber-500' : 'bg-rose-500'" />{{ n.title }}
          </NuxtLink>
          <p v-if="!notifications.length" class="px-3 py-4 text-sm text-slate-500">You're all caught up.</p>
        </UiPopover>
      </div>

      <!-- User menu -->
      <div ref="menuRef" class="relative">
        <button class="flex h-10 items-center gap-2.5 rounded-xl pr-2 pl-1 hover:bg-slate-200/50" @click="userMenu = !userMenu">
          <span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-brand-400 to-brand-700 text-xs font-bold text-white">{{ initials(auth.user?.name ?? '') }}</span>
          <span class="hidden text-left leading-tight xl:block">
            <span class="block text-[13px] font-semibold text-ink">{{ auth.user?.name }}</span>
            <span class="block text-[11px] text-slate-500">{{ auth.user?.role }}</span>
          </span>
        </button>
        <UiPopover :open="userMenu" class="right-0 w-56">
          <NuxtLink to="/account/profile" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm hover:bg-slate-50" @click="userMenu = false"><UserRound class="size-4 text-slate-400" />My profile</NuxtLink>
          <NuxtLink to="/account/password" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm hover:bg-slate-50" @click="userMenu = false"><KeyRound class="size-4 text-slate-400" />Change password</NuxtLink>
          <div class="my-1 border-t border-slate-100" />
          <button class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50" @click="auth.logout()"><LogOut class="size-4" />Sign out</button>
        </UiPopover>
      </div>
    </div>
  </header>
</template>
