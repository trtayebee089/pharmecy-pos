<template>
  <div class="mx-auto max-w-3xl px-4 py-10">
    <div class="rounded-[2rem] bg-white p-6 shadow-soft md:p-8">
      <!-- Sent -->
      <div v-if="sent" class="py-6 text-center" aria-live="polite">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-primary-50 text-primary-700"><CheckCircle class="h-8 w-8" /></div>
        <h1 class="mt-5 text-3xl font-black text-ink">Request received</h1>
        <p class="mt-2 text-slate-500">Your request number is <strong>{{ sent.number }}</strong>. Our pharmacist will call you on {{ form.phone }} to confirm the medicines and price.</p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
          <button type="button" class="rounded-full bg-slate-100 px-6 py-3 font-bold text-slate-700" @click="reset">Send another request</button>
          <RouterLink to="/" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white">Continue shopping</RouterLink>
        </div>
      </div>

      <template v-else>
        <h1 class="text-3xl font-black text-ink">Request a medicine</h1>
        <p class="mt-2 text-slate-500">Upload your prescription or write the medicines you need. Our pharmacist will call you to confirm and place the order.</p>

        <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
          <!-- Upload area -->
          <div>
            <p class="text-sm font-bold text-slate-700">Prescription <span class="font-normal text-slate-500">(photos or PDF, up to {{ MAX_FILES }} files, 5 MB each)</span></p>
            <label
              for="rx-files"
              class="mt-2 flex cursor-pointer flex-col items-center justify-center rounded-3xl border-2 border-dashed px-6 py-8 text-center transition"
              :class="dragging ? 'border-primary-500 bg-primary-50' : 'border-slate-200 bg-slate-50 hover:border-primary-300 hover:bg-primary-50/40'"
              @dragenter.prevent="dragging = true"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="onDrop"
            >
              <span class="grid h-14 w-14 place-items-center rounded-2xl bg-white text-primary-600 shadow-sm"><UploadCloud class="h-7 w-7" /></span>
              <span class="mt-3 font-bold text-ink">Drop files here or <span class="text-primary-700 underline">choose files</span></span>
              <span class="mt-1 text-xs text-slate-500">On a phone you can take a photo of the prescription</span>
              <input id="rx-files" ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,application/pdf" class="sr-only" @change="onPick" />
            </label>
            <p v-if="fileError" class="mt-2 text-sm text-red-600" role="alert">{{ fileError }}</p>

            <ul v-if="files.length" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="Selected files">
              <li v-for="(f, i) in files" :key="f.id" class="group relative overflow-hidden rounded-2xl bg-slate-50 ring-1 ring-slate-200">
                <img v-if="f.preview" :src="f.preview" :alt="f.file.name" class="aspect-[4/3] w-full object-cover" />
                <div v-else class="grid aspect-[4/3] place-items-center text-slate-500"><FileText class="h-10 w-10" /></div>
                <div class="flex items-center justify-between gap-2 px-3 py-2">
                  <span class="min-w-0">
                    <span class="block truncate text-xs font-semibold text-slate-700">{{ f.file.name }}</span>
                    <span class="text-[11px] text-slate-500">{{ size(f.file.size) }}</span>
                  </span>
                  <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-slate-500 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove ${f.file.name}`" @click="remove(i)"><X class="h-4 w-4" /></button>
                </div>
              </li>
            </ul>
          </div>

          <label class="text-sm font-bold text-slate-700">Medicine names <span class="font-normal text-slate-500">(optional if you upload a prescription)</span>
            <textarea v-model.trim="form.medicines" rows="4" maxlength="2000" placeholder="e.g. Napa 500 mg, 2 strips&#10;Seclo 20 mg, 1 box" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500"></textarea>
          </label>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-bold text-slate-700">Full name <input v-model.trim="form.name" required autocomplete="name" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
            <label class="text-sm font-bold text-slate-700">Phone number <input v-model.trim="form.phone" required inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" /></label>
          </div>
          <label class="text-sm font-bold text-slate-700">Delivery address <span class="font-normal text-slate-500">(optional)</span>
            <input v-model.trim="form.address" autocomplete="street-address" class="mt-2 w-full rounded-2xl bg-slate-50 px-4 py-3 outline-none focus:ring-2 focus:ring-primary-500" />
          </label>

          <p v-if="error" class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
          <div v-if="sending && files.length" class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-primary-600 transition-all" :style="{ width: `${progress}%` }"></div>
          </div>
          <button :disabled="sending" class="rounded-full bg-primary-600 px-6 py-3 font-bold text-white hover:bg-primary-700 disabled:opacity-50">
            {{ sending ? (files.length ? `Uploading… ${progress}%` : 'Sending…') : 'Send request' }}
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, reactive, ref } from 'vue'
import { CheckCircle, FileText, UploadCloud, X } from 'lucide-vue-next'
import { orderService } from '../services/orderService'
import { useAuthStore } from '../stores/authStore'

const MAX_FILES = 5
const MAX_BYTES = 5 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf']

const auth = useAuthStore()
const form = reactive({ name: auth.user?.name || '', phone: auth.user?.phone || auth.user?.phone_number || '', address: auth.user?.address || '', medicines: '' })
const files = ref([])
const fileInput = ref(null)
const dragging = ref(false)
const fileError = ref('')
const error = ref('')
const sending = ref(false)
const progress = ref(0)
const sent = ref(null)
let seq = 0

function add(list) {
  fileError.value = ''
  for (const file of list) {
    if (files.value.length >= MAX_FILES) { fileError.value = `You can add up to ${MAX_FILES} files.`; break }
    const heic = /\.heic$/i.test(file.name)
    if (!TYPES.includes(file.type) && !heic) { fileError.value = `${file.name}: use a photo (JPG, PNG, WebP, HEIC) or a PDF.`; continue }
    if (file.size > MAX_BYTES) { fileError.value = `${file.name} is larger than 5 MB.`; continue }
    const preview = file.type.startsWith('image/') && !heic ? URL.createObjectURL(file) : null
    files.value.push({ id: ++seq, file, preview })
  }
}
function onPick(e) {
  add([...(e.target.files || [])])
  e.target.value = '' // allows choosing the same file again after removing it
}
function onDrop(e) {
  dragging.value = false
  add([...(e.dataTransfer?.files || [])])
}
function remove(i) {
  const [f] = files.value.splice(i, 1)
  if (f?.preview) URL.revokeObjectURL(f.preview)
}
const size = (b) => (b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

async function submit() {
  error.value = ''
  if (!form.name || !form.phone) { error.value = 'Enter your name and phone number.'; return }
  if (!form.medicines && !files.value.length) { error.value = 'Upload your prescription or write the medicine names.'; return }
  sending.value = true
  progress.value = 0
  try {
    sent.value = await orderService.requestMedicine(
      { name: form.name, phone: form.phone, address: form.address, medicines: form.medicines },
      files.value.map((f) => f.file),
      (p) => { progress.value = p },
    )
  } catch (e) {
    const first = Object.values(e.errors || {})[0]
    error.value = (Array.isArray(first) ? first[0] : first) || e.message || 'The request could not be sent. Please try again.'
  } finally {
    sending.value = false
  }
}

function reset() {
  files.value.forEach((f) => f.preview && URL.revokeObjectURL(f.preview))
  files.value = []
  form.medicines = ''
  sent.value = null
}

onBeforeUnmount(() => files.value.forEach((f) => f.preview && URL.revokeObjectURL(f.preview)))
</script>
