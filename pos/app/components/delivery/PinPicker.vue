<script setup lang="ts">
import { LocateFixed } from 'lucide-vue-next'
import type { LatLng, MapsConfig } from '~/composables/useGoogleMaps'

/**
 * "Pick on map": a Google map in a stacked modal. Click the map or drag the pin,
 * then "Use this position". Needs Integrations › Maps switched on with a browser key.
 */
const open = defineModel<boolean>({ default: false })
const props = defineProps<{ config: MapsConfig; value: LatLng | null; title?: string }>()
const emit = defineEmits<{ pick: [at: LatLng] }>()

const gm = useGoogleMaps()
const toast = useToast()
const el = ref<HTMLElement | null>(null)
const at = ref<LatLng | null>(null)
let map: any = null
let marker: any = null

const round6 = (n: number) => Math.round(n * 1e6) / 1e6
function place(p: LatLng) {
  at.value = { lat: round6(p.lat), lng: round6(p.lng) }
  if (marker) marker.setPosition(at.value)
  else {
    marker = gm.dropPin(map, at.value, { draggable: true, title: 'Drag to adjust' })
    marker.addListener('dragend', (e: any) => place({ lat: e.latLng.lat(), lng: e.latLng.lng() }))
  }
}

watch(open, async (v) => {
  if (!v) return
  at.value = props.value
  const g = await gm.init(props.config)
  await nextTick()
  if (!g || !el.value) return
  gm.clear()
  marker = null
  const start = props.value ?? { lat: props.config.defaultLat, lng: props.config.defaultLng }
  map = gm.createMap(el.value, start, props.value ? 17 : 13, { gestureHandling: 'greedy' })
  if (props.value) place(props.value)
  map.addListener('click', (e: any) => place({ lat: e.latLng.lat(), lng: e.latLng.lng() }))
})

function useMine() {
  navigator.geolocation?.getCurrentPosition(
    (p) => { const here = { lat: p.coords.latitude, lng: p.coords.longitude }; place(here); map?.setCenter(here); map?.setZoom(17) },
    () => toast.warning('Location not available', 'Allow location access in the browser, or click the map.'),
    { enableHighAccuracy: true, timeout: 10000 },
  )
}
function confirm() {
  if (!at.value) return
  emit('pick', at.value)
  open.value = false
}
</script>

<template>
  <UiModal v-model="open" :title="title ?? 'Pick on map'" description="Click the map or drag the pin to the exact entrance." size="xl" stacked>
    <p v-if="gm.error.value" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ gm.error.value }}</p>
    <div v-else ref="el" class="h-[420px] w-full overflow-hidden rounded-xl bg-slate-100" />
    <p class="mt-2 text-[12.5px] text-slate-500 tabular">{{ at ? `${at.lat}, ${at.lng}` : 'No position chosen yet' }}</p>
    <template #footer>
      <UiButton variant="ghost" :icon="LocateFixed" class="mr-auto" @click="useMine">My location</UiButton>
      <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
      <UiButton variant="primary" :disabled="!at" @click="confirm">Use this position</UiButton>
    </template>
  </UiModal>
</template>
