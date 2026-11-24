/**
 * People & loyalty API modules (/api/pos/v1). With useMocks on (development
 * only) they resolve from app/mocks/people.ts.
 */
import type { Campaign, CustomerGroup, CustomerProfile, DueCustomer, Member, MembershipPlan, OpenInvoice, RewardEntry, RewardWallet } from '~/types/people'

const peopleMocks = () => import('~/mocks/people')

export function useCustomerGroupsResource() {
  return useResource<CustomerGroup>('customer-groups', async () => (await peopleMocks()).groups)
}

export function usePeopleApi() {
  const api = useApi()
  return {
    async dueCustomers(): Promise<DueCustomer[]> {
      if (api.useMocks) { await mockDelay(); return (await peopleMocks()).dueCustomers() }
      return api.get('/customers/due')
    },
    async openInvoices(customerId: number): Promise<OpenInvoice[]> {
      if (api.useMocks) { await mockDelay(200); return (await peopleMocks()).openInvoices(customerId) }
      return api.get(`/customers/${customerId}/open-invoices`)
    },
    /** Posts one payment and allocates it to invoices. The API re-validates allocations against current dues. */
    async collect(customerId: number, payload: { amount: number; method: string; reference: string; allocations: { saleId: number; amount: number }[] }, idempotencyKey: string): Promise<{ receiptNo: string }> {
      if (api.useMocks) { await mockDelay(700); return { receiptNo: `RCV-2026-${String(5100 + Math.floor(Math.random() * 800)).padStart(5, '0')}` } }
      return api.post(`/customers/${customerId}/payments`, payload, idempotencyKey)
    },
    /** Queues an SMS with the outstanding due through the configured SMS gateway. */
    async sendDueReminder(customerId: number, idempotencyKey: string): Promise<{ queued: boolean }> {
      if (api.useMocks) { await mockDelay(500); return { queued: true } }
      return api.post(`/customers/${customerId}/due-reminders`, {}, idempotencyKey)
    },
    async profile(id: number): Promise<CustomerProfile | null> {
      if (api.useMocks) { await mockDelay(300); return (await peopleMocks()).profile(id) }
      return api.get(`/customers/${id}`)
    },
  }
}

export function useLoyaltyApi() {
  const api = useApi()
  return {
    async wallets(): Promise<RewardWallet[]> {
      if (api.useMocks) { await mockDelay(); return (await peopleMocks()).wallets() }
      return api.get('/loyalty/wallets')
    },
    async ledger(customerId: number): Promise<RewardEntry[]> {
      if (api.useMocks) { await mockDelay(200); return (await peopleMocks()).walletLedger(customerId) }
      return api.get(`/loyalty/wallets/${customerId}/ledger`)
    },
    /** Manual points adjustment; posted to the reward ledger with the reason. */
    async adjust(customerId: number, points: number, reason: string, idempotencyKey: string): Promise<void> {
      if (api.useMocks) { await mockDelay(500); return }
      return api.post(`/loyalty/wallets/${customerId}/adjustments`, { points, reason }, idempotencyKey)
    },
    campaigns: useResource<Campaign>('loyalty/campaigns', async () => (await peopleMocks()).campaigns),
    async plans(): Promise<MembershipPlan[]> {
      if (api.useMocks) { await mockDelay(); return structuredClone((await peopleMocks()).plans) }
      return api.get('/loyalty/membership-plans')
    },
    async savePlan(plan: MembershipPlan): Promise<MembershipPlan> {
      if (api.useMocks) { await mockDelay(500); return plan }
      return api.put(`/loyalty/membership-plans/${plan.id}`, plan)
    },
    /** Plans with members cannot be deleted (pause them instead); the API enforces this. */
    async deletePlan(id: number): Promise<void> {
      if (api.useMocks) { await mockDelay(400); return }
      return api.del(`/loyalty/membership-plans/${id}`)
    },
    /** Extends a membership by the plan duration; a fee, if any, is collected as a payment by the API. */
    async renewMember(memberId: number, idempotencyKey: string): Promise<{ endsAt: string }> {
      if (api.useMocks) {
        await mockDelay(500)
        const m = (await peopleMocks()).members().find(x => x.id === memberId)
        const plan = (await peopleMocks()).plans.find(p => p.code === m?.plan)
        const base = m && new Date(m.endsAt) > new Date() ? new Date(m.endsAt) : new Date()
        base.setMonth(base.getMonth() + (plan?.durationMonths ?? 12))
        return { endsAt: base.toISOString() }
      }
      return api.post(`/loyalty/members/${memberId}/renew`, {}, idempotencyKey)
    },
    async changeMemberPlan(memberId: number, plan: Member['plan'], reason: string): Promise<void> {
      if (api.useMocks) { await mockDelay(450); return }
      return api.post(`/loyalty/members/${memberId}/plan`, { plan, reason })
    },
    async cancelMember(memberId: number, reason: string): Promise<void> {
      if (api.useMocks) { await mockDelay(400); return }
      return api.post(`/loyalty/members/${memberId}/cancel`, { reason })
    },
    async members(): Promise<Member[]> {
      if (api.useMocks) { await mockDelay(); return (await peopleMocks()).members() }
      return api.get('/loyalty/members')
    },
  }
}
