<template>
  <!-- Optional exact delivery point for the rider. Hidden when maps are off. -->
  <div v-if="site.mapsEnabled" class="rounded-2xl bg-slate-50 p-4">
    <div class="flex flex-wrap items-center gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full" :class="modelValue ? 'bg-accent-50 text-accent-700' : 'bg-white text-primary-600'">
        <MapPinCheck v-if="modelValue" class="h-5 w-5" />
        <MapPin v-else class="h-5 w-5" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-slate-700">
          {{ modelValue ? 'Exact location pinned' : 'Pin your exact location on the map' }}
          <span v-if="!modelValue" class="font-normal text-slate-500">(optional)</span>
        </p>
        <p class="text-xs text-slate-500">
          {{ modelValue ? `${modelValue.lat.toFixed(5)}, ${modelValue.lng.toFixed(5)} · the rider will navigate straight to it` : 'Helps our rider find you faster.' }}
        </p>
      </div>
      <div class="flex gap-2">
        <button type="button" class="rounded-full bg-white px-4 py-2 text-sm font-bold text-primary-700 shadow-card hover:bg-primary-50" @click="openPicker">
          {{ modelValue ? 'Change' : 'Pin location' }}
        </button>
        <button v-if="modelValue" type="button" class="rounded-full px-3 py-2 text-sm font-bold text-slate-500 hover:bg-slate-100" @click="emit('update:modelValue', null)">Remove</button>
      </div>
    </div>

    <Teleport to="body">
      <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-[80] flex items-end justify-center bg-ink/50 md:items-center md:p-6" @click.self="close">
          <div role="dialog" aria-modal="true" aria-labelledby="pin-title" class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-lift md:max-w-2xl md:rounded-3xl">
            <div class="flex items-center justify-between gap-3 px-5 pb-3 pt-5">
              <div class="min-w-0">
                <h2 id="pin-title" class="text-lg font-black text-ink">Pin your exact location</h2>
                <p class="text-xs text-slate-500">Move the map so the pin sits on your door.</p>
              </div>
              <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="Close" @click="close"><X class="h-5 w-5" /></button>
            </div>

            <div class="relative h-[55vh] min-h-[280px] bg-slate-100 md:h-[420px]">
              <div ref="mapEl" class="absolute inset-0"></div>
              <!-- Centre pin: the map moves under it, so a tap-and-drag anywhere sets the point. -->
              <div v-if="ready" class="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-full" aria-hidden="true">
                <MapPin class="h-11 w-11 fill-primary-600 text-white drop-shadow-lg transition-transform" :class="moving && '-translate-y-2'" :stroke-width="1.6" />
              </div>
              <div v-if="ready" class="pointer-events-none absolute left-1/2 top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-ink/40" aria-hidden="true"></div>
              <div v-if="!ready && !loadError" class="absolute inset-0 grid place-items-center text-sm font-semibold text-slate-500">Loading map…</div>
              <div v-if="loadError" class="absolute inset-0 grid place-items-center p-6 text-center text-sm text-slate-600">{{ loadError }}</div>
              <button
                v-if="ready"
                type="button"
                class="absolute bottom-4 left-4 inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-bold text-ink shadow-lift hover:bg-slate-50 disabled:opacity-60"
                :disabled="locating"
                @click="useMyLocation"
              >
                <LocateFixed class="h-4 w-4 text-primary-600" :class="locating && 'animate-pulse'" />
                {{ locating ? 'Finding you…' : 'Use my current location' }}
              </button>
            </div>

            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
              <p class="min-w-0 flex-1 text-xs" :class="pinError ? 'text-red-600' : 'text-slate-500'" role="status">
                {{ pinError || (center ? `${center.lat.toFixed(5)}, ${center.lng.toFixed(5)}` : '') }}
              </p>
              <div class="flex gap-2">
                <button type="button" class="flex-1 rounded-full bg-slate-100 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none" @click="close">Cancel</button>
                <button type="button" class="flex-1 rounded-full bg-primary-600 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-slate-300 sm:flex-none" :disabled="!ready || !center || !inBangladesh(center)" @click="confirm">
                  Confirm location
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, shallowRef } from 'vue'
import { LocateFixed, MapPin, MapPinCheck, X } from 'lucide-vue-next'
import { useSiteStore } from '../../stores/siteStore'
import { BASE_MAP_OPTIONS, inBangladesh, loadGoogleMaps } from '../../composables/useGoogleMaps'

const props = defineProps({
  /** `{ lat, lng }` or null */
  modelValue: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue'])

const site = useSiteStore()
const open = ref(false)
const ready = ref(false)
const moving = ref(false)
const locating = ref(false)
const loadError = ref('')
const pinError = ref('')
const center = ref(null)
const mapEl = ref(null)
const map = shallowRef(null)

function onKey(e) { if (e.key === 'Escape') close() }

async function openPicker() {
  open.value = true
  ready.value = false
  loadError.value = ''
  pinError.value = ''
  document.addEventListener('keydown', onKey)
  document.body.style.overflow = 'hidden'
  await nextTick()
  try {
    const { Map } = await loadGoogleMaps(site.maps.browser_key)
    if (!open.value || !mapEl.value) return
    const start = props.modelValue || site.maps.default_center
    map.value = new Map(mapEl.value, { ...BASE_MAP_OPTIONS, center: start, zoom: props.modelValue ? 17 : 13 })
    center.value = { ...start }
    map.value.addListener('dragstart', () => { moving.value = true })
    map.value.addListener('idle', () => {
      moving.value = false
      const c = map.value.getCenter()
      center.value = { lat: c.lat(), lng: c.lng() }
      pinError.value = inBangladesh(center.value) ? '' : 'Please choose a location inside Bangladesh.'
    })
    ready.value = true
    // No saved pin yet: start where the customer is, if the browser already allows it.
    if (!props.modelValue) useMyLocation(true)
  } catch {
    loadError.value = 'The map could not be loaded. You can still place the order with your written address.'
  }
}

function useMyLocation(quiet = false) {
  if (!navigator.geolocation) {
    if (!quiet) pinError.value = 'Your browser cannot share its location. Move the map instead.'
    return
  }
  if (quiet && navigator.permissions?.query) {
    // Only auto-locate when permission was already granted (no surprise prompt on open).
    navigator.permissions.query({ name: 'geolocation' }).then((s) => { if (s.state === 'granted') locate(true) }).catch(() => {})
    return
  }
  locate(quiet)
}

function locate(quiet) {
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      locating.value = false
      if (!map.value) return
      map.value.panTo({ lat: pos.coords.latitude, lng: pos.coords.longitude })
      map.value.setZoom(17)
    },
    (err) => {
      locating.value = false
      if (!quiet) pinError.value = err.code === 1 ? 'Location permission was denied. Move the map to your door instead.' : 'Could not find your location. Move the map to your door instead.'
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 },
  )
}

function confirm() {
  if (!center.value || !inBangladesh(center.value)) return
  const round = (v) => Math.round(v * 1e6) / 1e6
  emit('update:modelValue', { lat: round(center.value.lat), lng: round(center.value.lng) })
  close()
}

function close() {
  open.value = false
  ready.value = false
  map.value = null
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
}

onBeforeUnmount(close)
</script>
