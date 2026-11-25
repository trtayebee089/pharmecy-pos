<script setup lang="ts">
import { ImagePlus, Pencil, Trash2, ArrowUp, ArrowDown, Plus, Save, EyeOff } from 'lucide-vue-next'
import type { Banner, BannerForm } from '~/services/integrations'

definePageMeta({ title: 'Website banners' })
const api = useBannersApi()
const toast = useToast()
const confirm = useConfirm()

const banners = ref<Banner[]>([])
const tileSlots = ref(3)
const loading = ref(true)
const today = new Date().toISOString().slice(0, 10)

const live = (b: Banner) => b.is_active && (!b.starts_on || b.starts_on <= today) && (!b.ends_on || b.ends_on >= today)
const heroes = computed(() => banners.value.filter(b => b.placement === 'hero'))
const tiles = computed(() => banners.value.filter(b => b.placement === 'tile'))
const liveHero = computed(() => heroes.value.find(live))
const liveTiles = computed(() => tiles.value.filter(live).slice(0, tileSlots.value))

async function load() {
  loading.value = true
  try { const r = await api.list(); banners.value = r.banners; tileSlots.value = r.tileSlots }
  catch (e: any) { toast.error('Banners not loaded', e?.message) }
  finally { loading.value = false }
}
onMounted(load)

// ---------------------------------------------------------------- editor
const open = ref(false)
const editing = ref<Banner | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const blank = (placement: 'hero' | 'tile'): BannerForm => ({ placement, title: '', subtitle: '', button_text: '', link_url: '', is_active: true, starts_on: '', ends_on: '', image: null, mobile_image: null, remove_mobile_image: false })
const f = ref<BannerForm>(blank('hero'))
const preview = ref<string | null>(null)
const mobilePreview = ref<string | null>(null)

function add(placement: 'hero' | 'tile') {
  editing.value = null
  f.value = blank(placement)
  preview.value = mobilePreview.value = null
  errors.value = {}
  open.value = true
}
function edit(b: Banner) {
  editing.value = b
  f.value = { placement: b.placement, title: b.title, subtitle: b.subtitle ?? '', button_text: b.button_text ?? '', link_url: b.link_url ?? '', is_active: b.is_active, starts_on: b.starts_on ?? '', ends_on: b.ends_on ?? '', image: null, mobile_image: null, remove_mobile_image: false }
  preview.value = b.image
  mobilePreview.value = b.mobile_image
  errors.value = {}
  open.value = true
}
function pick(e: Event, field: 'image' | 'mobile_image') {
  const file = (e.target as HTMLInputElement).files?.[0] ?? null
  if (file && file.size > 3 * 1024 * 1024) { toast.error('Image too large', 'Use an image under 3 MB (JPG, PNG or WebP).'); return }
  f.value[field] = file
  const url = file ? URL.createObjectURL(file) : null
  if (field === 'image') preview.value = url ?? editing.value?.image ?? null
  else { mobilePreview.value = url; f.value.remove_mobile_image = false }
}
async function save() {
  saving.value = true
  errors.value = {}
  try {
    await api.save(f.value, editing.value?.id)
    toast.success(editing.value ? 'Banner updated' : 'Banner added', 'The website shows it on the next page load.')
    open.value = false
    await load()
  } catch (e: any) {
    errors.value = e?.errors ?? {}
    toast.error('Banner not saved', e?.message)
  } finally { saving.value = false }
}
const err = (k: string) => errors.value[k]?.[0]

async function remove(b: Banner) {
  const { ok } = await confirm.ask({ title: `Delete “${b.title}”?`, description: 'The banner and its images are removed from the website.', confirmLabel: 'Delete', tone: 'danger' })
  if (!ok) return
  try { await api.remove(b.id); toast.success('Banner deleted'); await load() }
  catch (e: any) { toast.error('Not deleted', e?.message) }
}

async function move(list: Banner[], index: number, delta: number) {
  const ids = list.map(b => b.id)
  const to = index + delta
  if (to < 0 || to >= ids.length) return
  ;[ids[index], ids[to]] = [ids[to]!, ids[index]!]
  try { const r = await api.reorder(ids); banners.value = r.banners }
  catch (e: any) { toast.error('Order not saved', e?.message) }
}
</script>

<template>
  <div>
    <UiPageHeader title="Website banners" description="The home page shows one wide main banner (several rotate) and three tiles under it.">
      <UiButton :icon="Plus" @click="add('tile')">Add tile</UiButton>
      <UiButton variant="primary" :icon="Plus" @click="add('hero')">Add main banner</UiButton>
    </UiPageHeader>

    <!-- Live preview of the layout -->
    <section class="card mb-5 p-4" aria-label="How the home page looks today">
      <p class="mb-3 text-[13px] font-semibold text-slate-600">On the website today</p>
      <div class="space-y-2.5 rounded-2xl bg-slate-50 p-3">
        <div class="relative aspect-[3/1] overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
          <img v-if="liveHero" :src="liveHero.image" :alt="liveHero.title" class="h-full w-full object-cover">
          <p v-else class="grid h-full place-items-center px-4 text-center text-sm text-slate-500">No main banner: the website shows its built-in hero</p>
          <span v-if="heroes.filter(live).length > 1" class="absolute right-2 top-2 rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-semibold">Rotates {{ heroes.filter(live).length }} banners</span>
        </div>
        <div class="grid grid-cols-3 gap-2.5">
          <div v-for="i in tileSlots" :key="i" class="aspect-[16/10] overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
            <img v-if="liveTiles[i - 1]" :src="liveTiles[i - 1]!.image" :alt="liveTiles[i - 1]!.title" class="h-full w-full object-cover">
            <p v-else class="grid h-full place-items-center px-2 text-center text-[12px] text-slate-400">{{ liveTiles.length ? 'Empty' : 'Built-in tile' }}</p>
          </div>
        </div>
      </div>
      <p class="mt-2 text-[12px] text-slate-500">Image sizes: main banner 1600 × 533 (3:1), its phone version 800 × 800; tiles 800 × 500 (16:10). JPG, PNG or WebP up to 3 MB.</p>
    </section>

    <div v-if="loading" class="grid gap-5 lg:grid-cols-2"><div v-for="i in 2" :key="i" class="skeleton h-48" /></div>
    <div v-else class="grid gap-5 lg:grid-cols-2">
      <UiSection v-for="group in ([{ key: 'hero', title: 'Main banners', list: heroes }, { key: 'tile', title: `Tiles (first ${tileSlots} shown)`, list: tiles }] as const)" :key="group.key" :title="group.title" :icon="ImagePlus">
        <p v-if="!group.list.length" class="py-6 text-center text-sm text-slate-500">None yet.</p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="(b, i) in group.list" :key="b.id" class="flex items-center gap-3 py-3">
            <img :src="b.image" :alt="b.title" class="h-12 w-20 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold text-ink">{{ b.title }}</p>
              <p class="truncate text-[12px] text-slate-500">
                <span v-if="!live(b)" class="inline-flex items-center gap-1 font-semibold text-amber-700"><EyeOff class="size-3" /> {{ !b.is_active ? 'Hidden' : 'Outside its dates' }} · </span>
                {{ b.link_url || 'No link' }}
              </p>
            </div>
            <div class="flex shrink-0 items-center">
              <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" :aria-label="`Move ${b.title} up`" @click="move([...group.list], i, -1)"><ArrowUp class="size-4" /></button>
              <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="i === group.list.length - 1" :aria-label="`Move ${b.title} down`" @click="move([...group.list], i, 1)"><ArrowDown class="size-4" /></button>
              <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" :aria-label="`Edit ${b.title}`" @click="edit(b)"><Pencil class="size-4" /></button>
              <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-rose-50 hover:text-rose-600" :aria-label="`Delete ${b.title}`" @click="remove(b)"><Trash2 class="size-4" /></button>
            </div>
          </li>
        </ul>
      </UiSection>
    </div>

    <UiDrawer v-model="open" :title="editing ? 'Edit banner' : (f.placement === 'hero' ? 'New main banner' : 'New tile')" description="Text over the image is optional: leave subtitle and button empty when the image has its own text.">
      <div class="space-y-4">
        <div>
          <p class="mb-2 text-[13px] font-medium text-slate-700">Where</p>
          <UiSegmented v-model="f.placement" :options="[{ value: 'hero', label: 'Main banner' }, { value: 'tile', label: 'Tile' }]" />
        </div>
        <div>
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Image {{ f.placement === 'hero' ? '(1600 × 533)' : '(800 × 500)' }}</p>
          <label class="block cursor-pointer overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 hover:border-brand-400" :class="f.placement === 'hero' ? 'aspect-[3/1]' : 'aspect-[16/10]'">
            <img v-if="preview" :src="preview" alt="" class="h-full w-full object-cover">
            <span v-else class="grid h-full place-items-center text-sm text-slate-500"><span class="flex items-center gap-2"><ImagePlus class="size-5" /> Choose an image</span></span>
            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="pick($event, 'image')">
          </label>
          <p v-if="err('image')" class="mt-1 text-[12px] text-rose-600">{{ err('image') }}</p>
        </div>
        <div v-if="f.placement === 'hero'">
          <p class="mb-1.5 text-[13px] font-medium text-slate-700">Phone version (optional, 800 × 800)</p>
          <div class="flex items-center gap-3">
            <label class="block size-24 shrink-0 cursor-pointer overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 hover:border-brand-400">
              <img v-if="mobilePreview && !f.remove_mobile_image" :src="mobilePreview" alt="" class="h-full w-full object-cover">
              <span v-else class="grid h-full place-items-center"><ImagePlus class="size-5 text-slate-400" /></span>
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="pick($event, 'mobile_image')">
            </label>
            <UiButton v-if="mobilePreview && !f.remove_mobile_image" size="sm" variant="ghost" @click="f.remove_mobile_image = true; f.mobile_image = null">Remove</UiButton>
            <p class="text-[12px] text-slate-500">Without it, phones show the wide image.</p>
          </div>
        </div>
        <UiInput v-model="f.title" label="Title" hint="Also the image's description for screen readers and search" :error="err('title')" />
        <UiInput v-model="f.subtitle" label="Subtitle (optional)" :error="err('subtitle')" />
        <div class="grid gap-4 sm:grid-cols-2">
          <UiInput v-model="f.button_text" label="Button text (optional)" :error="err('button_text')" />
          <UiInput v-model="f.link_url" label="Link" placeholder="/category/vitamins" :error="err('link_url')" hint="A site path or https:// link" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <UiInput v-model="f.starts_on" type="date" label="Show from (optional)" :error="err('starts_on')" />
          <UiInput v-model="f.ends_on" type="date" label="Show until (optional)" :error="err('ends_on')" />
        </div>
        <UiSwitch v-model="f.is_active" label="Show on the website" />
      </div>
      <template #footer>
        <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
        <UiButton variant="primary" :icon="Save" :loading="saving" :disabled="!f.title || (!editing && !f.image)" @click="save">{{ editing ? 'Save banner' : 'Add banner' }}</UiButton>
      </template>
    </UiDrawer>
  </div>
</template>
