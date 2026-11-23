import type { ListQuery, Paginated } from '~/types/domain'

/** Search + pagination + filters with debounced reload, synced to the URL query. */
export function useList<T>(fetcher: (q: ListQuery) => Promise<Paginated<T>>, initialFilters: Record<string, any> = {}) {
  const route = useRoute()
  const router = useRouter()
  const search = ref(String(route.query.search ?? ''))
  const page = ref(Number(route.query.page ?? 1))
  const filters = ref<Record<string, any>>({ ...initialFilters })
  const result = ref<Paginated<T> | null>(null) as Ref<Paginated<T> | null>
  const loading = ref(false)
  const error = ref<string | null>(null)
  let seq = 0

  async function load() {
    const my = ++seq
    loading.value = true
    error.value = null
    try {
      const r = await fetcher({ search: search.value, page: page.value, perPage: 15, filters: filters.value })
      if (my === seq) result.value = r
    }
    catch (e: any) {
      if (my === seq) { error.value = e?.message ?? 'Failed to load'; useToast().error('Could not load data', error.value ?? undefined) }
    }
    finally { if (my === seq) loading.value = false }
  }

  watchDebounced(search, () => { page.value = 1; load() }, { debounce: 250 })
  watch(filters, () => { page.value = 1; load() }, { deep: true })
  watch(page, load)
  watch([search, page], () => router.replace({ query: { ...route.query, search: search.value || undefined, page: page.value > 1 ? page.value : undefined } }))
  onMounted(load)

  return { search, page, filters, result, loading, error, reload: load }
}
