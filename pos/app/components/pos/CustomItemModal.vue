<script setup lang="ts">
import { PackagePlus } from 'lucide-vue-next'

/** Legacy "custom product": sell an item that is not in the catalogue. No stock movement. */
const open = defineModel<boolean>({ default: false })
const cart = useCartStore()
const toast = useToast()
const form = reactive({ name: '', code: '', price: 0, qty: 1 })
const error = ref('')
watch(open, o => { if (o) { Object.assign(form, { name: '', code: '', price: 0, qty: 1 }); error.value = '' } })

function add() {
  if (form.name.trim().length < 2) { error.value = 'Enter the item name'; return }
  if (!(form.price > 0)) { error.value = 'Price must be above 0'; return }
  if (!(form.qty >= 1)) { error.value = 'Quantity must be at least 1'; return }
  cart.addCustom(form.name.trim(), form.code.trim(), Number(form.price), Number(form.qty))
  toast.success('Custom item added', form.name)
  open.value = false
}
</script>

<template>
  <UiModal v-model="open" title="Add custom item" description="For items not in the catalogue. It is recorded on the invoice and in the custom product sales report, with no stock or batch." size="sm">
    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="add">
      <div class="sm:col-span-2"><UiInput v-model="form.name" label="Item name *" placeholder="e.g. Hot water bag" /></div>
      <UiInput v-model="form.code" label="Code (optional)" />
      <UiInput v-model.number="form.qty" type="number" label="Quantity" />
      <div class="sm:col-span-2"><UiInput v-model.number="form.price" type="number" label="Unit price (৳) *" /></div>
      <p v-if="error" class="text-sm font-medium text-rose-600 sm:col-span-2">{{ error }}</p>
      <button type="submit" class="hidden" />
    </form>
    <template #footer>
      <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
      <UiButton variant="primary" :icon="PackagePlus" @click="add">Add to cart</UiButton>
    </template>
  </UiModal>
</template>
