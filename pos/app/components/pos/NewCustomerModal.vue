<script setup lang="ts">
import { UserPlus } from 'lucide-vue-next'
import type { Customer } from '~/types/domain'

const open = defineModel<boolean>({ default: false })
const emit = defineEmits<{ created: [customer: Customer] }>()
const api = useCustomerWriteApi()
const toast = useToast()
const form = reactive({ name: '', phone: '', email: '', gender: '', address: '', group: '' })
const errors = ref<Record<string, string>>({})
const saving = ref(false)

watch(open, (o) => { if (o) { Object.assign(form, { name: '', phone: '', email: '', gender: '', address: '', group: '' }); errors.value = {} } })

async function save() {
  errors.value = {}
  if (form.name.trim().length < 2) errors.value.name = 'Name is required'
  const phone = normalizeBdPhone(form.phone)
  if (!phone) errors.value.phone = 'Enter a valid Bangladeshi mobile (01XXXXXXXXX)'
  if (form.email && !/^\S+@\S+\.\S+$/.test(form.email)) errors.value.email = 'Invalid email'
  if (Object.keys(errors.value).length) return
  saving.value = true
  try {
    const c = await api.create({ ...form, phone: phone! })
    toast.success('Customer added', `${c.name} · ${c.phone}`)
    emit('created', c)
    open.value = false
  }
  catch (e: any) {
    if (e instanceof ApiError) for (const [k, v] of Object.entries(e.errors)) errors.value[k] = v[0] ?? ''
    else toast.error('Could not add customer', e?.message)
  }
  finally { saving.value = false }
}
</script>

<template>
  <UiModal v-model="open" title="New customer" description="Phone numbers are unique; the counter finds customers by phone." :dismissible="!saving">
    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
      <div class="sm:col-span-2"><UiInput v-model="form.name" label="Full name *" :error="errors.name" /></div>
      <UiInput v-model="form.phone" label="Mobile *" placeholder="01XXXXXXXXX" inputmode="tel" :error="errors.phone" />
      <UiInput v-model="form.email" label="Email" type="email" :error="errors.email" />
      <UiSelect v-model="form.gender" label="Gender" :options="[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]" placeholder="Optional" />
      <UiSelect v-model="form.group" label="Customer group" :options="[{ value: 'Regular patient', label: 'Regular patient' }, { value: 'Corporate', label: 'Corporate' }]" placeholder="None" />
      <div class="sm:col-span-2"><UiTextarea v-model="form.address" label="Address" :rows="2" /></div>
      <button type="submit" class="hidden" />
    </form>
    <template #footer>
      <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
      <UiButton variant="primary" :icon="UserPlus" :loading="saving" @click="save">Add customer</UiButton>
    </template>
  </UiModal>
</template>
