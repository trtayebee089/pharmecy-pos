/**
 * Finance API modules (/api/pos/v1). With useMocks on (development only)
 * they resolve from app/mocks/finance.ts. Balances are always derived by the
 * API from account_transactions; the UI never sends a balance.
 */
import type { Account, BalanceSheet, FinanceCategory, FinanceEntry, MoneyTransfer, StatementLine } from '~/types/finance'

const financeMocks = () => import('~/mocks/finance')

export function useFinanceApi() {
  const api = useApi()
  const accountStore = useState<Account[] | null>('mock-finance-accounts', () => null)
  const transferStore = useState<MoneyTransfer[] | null>('mock-finance-transfers', () => null)
  const entryStore = useState<Record<string, FinanceEntry[]>>('mock-finance-entries', () => ({}))

  async function mockAccounts() {
    if (!accountStore.value) accountStore.value = structuredClone((await financeMocks()).accounts)
    return accountStore.value
  }
  async function mockEntries(kind: 'expenses' | 'incomes') {
    if (!entryStore.value[kind]) entryStore.value[kind] = structuredClone((await financeMocks())[kind])
    return entryStore.value[kind]!
  }

  return {
    async accounts(): Promise<Account[]> {
      if (api.useMocks) { await mockDelay(); return mockAccounts() }
      return api.get('/finance/accounts')
    },
    async saveAccount(a: Partial<Account>): Promise<Account> {
      if (api.useMocks) {
        await mockDelay(450)
        const list = await mockAccounts()
        if (a.id) { const i = list.findIndex(x => x.id === a.id); list[i] = { ...list[i]!, ...a }; return list[i]! }
        const created: Account = { id: Math.max(...list.map(x => x.id)) + 1, code: a.code ?? '', name: a.name ?? '', type: a.type ?? 'bank', accountNo: a.accountNo ?? '', openingBalance: Number(a.openingBalance) || 0, balance: Number(a.openingBalance) || 0, isDefault: false, isActive: true }
        list.push(created)
        return created
      }
      return a.id ? api.put(`/finance/accounts/${a.id}`, a) : api.post('/finance/accounts', a)
    },
    async statement(accountId: number, from: string, to: string): Promise<StatementLine[]> {
      if (api.useMocks) { await mockDelay(300); return (await financeMocks()).statement(accountId) }
      return api.get(`/finance/accounts/${accountId}/statement`, { from, to })
    },
    async transfers(): Promise<MoneyTransfer[]> {
      if (api.useMocks) {
        await mockDelay()
        if (!transferStore.value) transferStore.value = structuredClone((await financeMocks()).transfers)
        return transferStore.value
      }
      return api.get('/finance/money-transfers')
    },
    async transfer(payload: { fromAccountId: number; toAccountId: number; amount: number; date: string; note: string }, idempotencyKey: string): Promise<MoneyTransfer> {
      if (api.useMocks) {
        await mockDelay(600)
        const list = await mockAccounts()
        const from = list.find(a => a.id === payload.fromAccountId)!
        const to = list.find(a => a.id === payload.toAccountId)!
        from.balance -= payload.amount
        to.balance += payload.amount
        const t: MoneyTransfer = { id: Date.now(), number: `MT-2026-${String(41 + (transferStore.value?.length ?? 0)).padStart(5, '0')}`, date: new Date(payload.date).toISOString(), fromAccount: from.name, toAccount: to.name, amount: payload.amount, note: payload.note, createdBy: 'You' }
        transferStore.value = [t, ...(transferStore.value ?? [])]
        return t
      }
      return api.post('/finance/money-transfers', payload, idempotencyKey)
    },
    categories(kind: 'expense' | 'income') {
      return useResource<FinanceCategory>(`finance/${kind}-categories`, async () => (await financeMocks())[kind === 'expense' ? 'expenseCategories' : 'incomeCategories'])
    },
    async entries(kind: 'expenses' | 'incomes'): Promise<FinanceEntry[]> {
      if (api.useMocks) { await mockDelay(); return mockEntries(kind) }
      return api.get(`/finance/${kind}`)
    },
    async postEntry(kind: 'expenses' | 'incomes', payload: { date: string; categoryId: number; accountId: number; amount: number; note: string; category: string; account: string; hasAttachment: boolean }, idempotencyKey: string): Promise<FinanceEntry> {
      if (api.useMocks) {
        await mockDelay(600)
        const list = await mockEntries(kind)
        const n = Math.max(...list.map(e => e.id)) + 1
        const e: FinanceEntry = { id: n, number: `${kind === 'expenses' ? 'EXP' : 'INC'}-2026-${String(n).padStart(5, '0')}`, date: new Date(payload.date).toISOString(), category: payload.category, account: payload.account, amount: payload.amount, note: payload.note, hasAttachment: payload.hasAttachment, status: 'posted', createdBy: 'You' }
        list.unshift(e)
        return e
      }
      return api.post(`/finance/${kind}`, payload, idempotencyKey)
    },
    /** Voiding posts a reversing account transaction; the entry stays on record. */
    async voidEntry(kind: 'expenses' | 'incomes', id: number, reason: string): Promise<void> {
      if (api.useMocks) {
        await mockDelay(400)
        const e = (await mockEntries(kind)).find(x => x.id === id)
        if (e) { e.status = 'void'; e.voidReason = reason }
        return
      }
      return api.post(`/finance/${kind}/${id}/void`, { reason })
    },
    async balanceSheet(asOf: string): Promise<BalanceSheet> {
      if (api.useMocks) { await mockDelay(500); return (await financeMocks()).balanceSheet(asOf) }
      return api.get('/finance/balance-sheet', { as_of: asOf })
    },
  }
}
