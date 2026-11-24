import type { ApiDomain } from '~/composables/useApi'
import type { ListQuery, Paginated } from '~/types/domain'

/**
 * Generic REST resource for master data and simple documents
 * (GET/POST/PUT/DELETE /api/pos/v1/{resource}).
 *
 * With useMocks on (development only) rows live in an in-memory store seeded
 * once per session, so create/edit/delete behave realistically in the UI.
 */
export interface Resource<T extends { id: number }> {
  list: (q: ListQuery) => Promise<Paginated<T>>
  all: () => Promise<T[]>
  get: (id: number) => Promise<T | null>
  save: (item: Partial<T>) => Promise<T>
  remove: (id: number) => Promise<void>
}

export function useResource<T extends { id: number }>(resource: string, seed: () => T[] | Promise<T[]>, domain?: ApiDomain): Resource<T> {
  const api = useApi(domain)
  const store = useState<T[] | null>(`mock-resource:${resource}`, () => null)

  async function rows(): Promise<T[]> {
    if (!store.value) store.value = structuredClone(await seed()) as T[]
    return store.value as T[]
  }

  return {
    async list(q) {
      if (!api.useMocks) return api.get(`/${resource}`, { ...q, ...q.filters })
      await mockDelay(220)
      let data = await rows()
      for (const [k, v] of Object.entries(q.filters ?? {})) {
        if (v === '' || v === undefined || v === null || v === false) continue
        data = data.filter(r => String((r as any)[k]) === String(v))
      }
      return paginate(data, q, (r, s) => Object.values(r as any).some(v => typeof v === 'string' && v.toLowerCase().includes(s)))
    },
    async all() {
      if (!api.useMocks) return api.get(`/${resource}`, { all: 1 })
      await mockDelay(150)
      return rows()
    },
    async get(id) {
      if (!api.useMocks) return api.get(`/${resource}/${id}`)
      await mockDelay(150)
      return (await rows()).find(r => r.id === id) ?? null
    },
    async save(item) {
      if (!api.useMocks) return item.id ? api.put(`/${resource}/${item.id}`, item) : api.post(`/${resource}`, item)
      await mockDelay(400)
      const data = await rows()
      if (item.id) {
        const i = data.findIndex(r => r.id === item.id)
        if (i >= 0) data[i] = { ...data[i]!, ...item } as T
        return data[i]!
      }
      const created = { ...item, id: Math.max(0, ...data.map(r => r.id)) + 1 } as T
      data.unshift(created)
      return created
    },
    async remove(id) {
      if (!api.useMocks) return api.del(`/${resource}/${id}`)
      await mockDelay(300)
      store.value = (await rows()).filter(r => r.id !== id)
    },
  }
}
