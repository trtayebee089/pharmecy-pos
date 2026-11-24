import type { Customer } from '~/types/domain'

export interface NewCustomer { name: string; phone: string; email: string; gender: string; address: string; group: string }

/** Bangladeshi mobile: 01[3-9] followed by 8 digits; +880 / 880 prefixes normalised. */
export function normalizeBdPhone(raw: string): string | null {
  const digits = raw.replace(/\D/g, '').replace(/^880/, '0')
  return /^01[3-9]\d{8}$/.test(digits) ? digits : null
}

export function useCustomerWriteApi() {
  const api = useApi('customers')
  return {
    /** The API enforces a unique normalised phone; duplicates return 422 on `phone`. */
    async create(input: NewCustomer): Promise<Customer> {
      if (api.useMocks) {
        await mockDelay(500)
        const { customers } = await import('~/mocks/seed')
        if (customers.some(c => c.phone === input.phone)) throw new ApiError('The given data was invalid.', 422, { phone: ['A customer with this phone already exists.'] })
        const c: Customer = {
          id: Math.max(...customers.map(x => x.id)) + 1, code: `C-${String(customers.length + 1).padStart(4, '0')}`, name: input.name.trim(),
          phone: input.phone, group: input.group || null, dueBalance: 0, points: 0, membership: null, totalSpent: 0, lastVisit: null,
        }
        customers.push(c)
        return c
      }
      return api.post('/customers', input)
    },
  }
}
