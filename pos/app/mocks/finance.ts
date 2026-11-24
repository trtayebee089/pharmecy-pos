/**
 * DEVELOPMENT-ONLY MOCK DATA: accounts and statements, money transfers,
 * expenses, income, categories and the balance sheet.
 * Loaded only when runtimeConfig.public.useMocks is true.
 */
import type { Account, BalanceSheet, FinanceCategory, FinanceEntry, MoneyTransfer, StatementLine } from '~/types/finance'

const DAY = 864e5
const iso = (msAgo: number) => new Date(Date.now() - msAgo).toISOString()
const r2 = (n: number) => Math.round(n * 100) / 100

export const accounts: Account[] = [
  { id: 1, code: 'CASH-MAIN', name: 'Main cash drawer', type: 'cash', accountNo: '—', openingBalance: 25000, balance: 186_420.5, isDefault: true, isActive: true },
  { id: 2, code: 'BANK-DBBL', name: 'Dutch-Bangla Bank', type: 'bank', accountNo: '•••• 4821', openingBalance: 300000, balance: 1_248_300, isDefault: false, isActive: true },
  { id: 3, code: 'BANK-CITY', name: 'City Bank', type: 'bank', accountNo: '•••• 9076', openingBalance: 100000, balance: 412_950.75, isDefault: false, isActive: true },
  { id: 4, code: 'BKASH-MER', name: 'bKash merchant', type: 'mobile_wallet', accountNo: '01711-000999', openingBalance: 0, balance: 96_440, isDefault: false, isActive: true },
  { id: 5, code: 'NAGAD-MER', name: 'Nagad merchant', type: 'mobile_wallet', accountNo: '01811-000777', openingBalance: 0, balance: 21_150, isDefault: false, isActive: true },
]

/** Statement lines oldest→newest with running balance ending at the account balance. */
export function statement(accountId: number): StatementLine[] {
  const a = accounts.find(x => x.id === accountId)
  if (!a) return []
  const descs = a.type === 'cash'
    ? ['Counter 1 cash sales', 'Counter 2 cash sales', 'Supplier payment · Square Distribution', 'Petty expense · tea & snacks', 'Deposit to Dutch-Bangla Bank', 'Cash refund R-02101']
    : a.type === 'bank'
      ? ['Cash deposit from drawer', 'Supplier payment · Beximco Pharma Depot', 'Card settlement', 'Shop rent · November', 'Electricity bill · DESCO', 'Transfer to bKash float']
      : ['Customer payments', 'Settlement to Dutch-Bangla Bank', 'Customer payments', 'Refund to customer', 'Customer payments', 'Merchant fee']
  const raw = Array.from({ length: 18 }, (_, i) => {
    const d = descs[i % descs.length]!
    const isIn = /sales|settlement|Customer payments|deposit|Deposit from|Card/i.test(d) && !/Settlement to|Deposit to/.test(d)
    const amt = r2(1500 + ((accountId * 7919 + i * 3571) % 42000) + ((i * 37) % 100) / 100)
    return { date: iso((30 - i * 1.6) * DAY), description: d, ref: `TXN-${accountId}${String(4100 + i)}`, inAmount: isIn ? amt : 0, outAmount: isIn ? 0 : amt, balance: 0 }
  })
  const net = raw.reduce((s, l) => s + l.inAmount - l.outAmount, 0)
  let bal = r2(a.balance - net)
  return [{ date: iso(31 * DAY), description: 'Balance brought forward', ref: '—', inAmount: 0, outAmount: 0, balance: bal }, ...raw.map((l) => { bal = r2(bal + l.inAmount - l.outAmount); return { ...l, balance: bal } })]
}

export const transfers: MoneyTransfer[] = Array.from({ length: 14 }, (_, i) => {
  const pairs: [string, string][] = [['Main cash drawer', 'Dutch-Bangla Bank'], ['bKash merchant', 'Dutch-Bangla Bank'], ['Dutch-Bangla Bank', 'City Bank'], ['Nagad merchant', 'City Bank'], ['Dutch-Bangla Bank', 'bKash merchant']]
  const [f, t] = pairs[i % pairs.length]!
  return { id: 40 - i, number: `MT-2026-${String(40 - i).padStart(5, '0')}`, date: iso((i * 2.2 + 0.3) * DAY), fromAccount: f, toAccount: t, amount: r2(10000 + ((i * 7907) % 90000)), note: i % 2 ? 'Daily deposit' : 'Float top-up', createdBy: i % 3 ? 'Fahim Rahman' : 'Sabbir Hossain' }
})

export const expenseCategories: FinanceCategory[] = [
  { id: 1, name: 'Rent', parent: '', entries: 11, isActive: true },
  { id: 2, name: 'Utilities', parent: '', entries: 0, isActive: true },
  { id: 3, name: 'Electricity', parent: 'Utilities', entries: 11, isActive: true },
  { id: 4, name: 'Internet', parent: 'Utilities', entries: 11, isActive: true },
  { id: 5, name: 'Salaries & wages', parent: '', entries: 22, isActive: true },
  { id: 6, name: 'Delivery & courier', parent: '', entries: 48, isActive: true },
  { id: 7, name: 'Petty cash', parent: '', entries: 96, isActive: true },
  { id: 8, name: 'Repairs & maintenance', parent: '', entries: 7, isActive: true },
  { id: 9, name: 'Marketing', parent: '', entries: 5, isActive: true },
  { id: 10, name: 'Bank & wallet charges', parent: '', entries: 31, isActive: true },
]

export const incomeCategories: FinanceCategory[] = [
  { id: 1, name: 'Supplier incentive', parent: '', entries: 6, isActive: true },
  { id: 2, name: 'Display & shelf rent', parent: '', entries: 4, isActive: true },
  { id: 3, name: 'Delivery charge collected', parent: '', entries: 37, isActive: true },
  { id: 4, name: 'Bank interest', parent: '', entries: 3, isActive: true },
  { id: 5, name: 'Scrap / carton sale', parent: '', entries: 2, isActive: true },
]

function entries(prefix: string, cats: FinanceCategory[], n: number, base: number): FinanceEntry[] {
  const accts = ['Main cash drawer', 'Dutch-Bangla Bank', 'bKash merchant', 'City Bank']
  const usable = cats.filter(c => c.name !== 'Utilities')
  return Array.from({ length: n }, (_, i) => {
    const c = usable[(i * 5) % usable.length]!
    return {
      id: 300 - i, number: `${prefix}-2026-${String(300 - i).padStart(5, '0')}`, date: iso((i * 1.4 + 0.2) * DAY), category: c.name,
      account: accts[i % accts.length]!, amount: r2(base * (0.2 + ((i * 7919) % 100) / 60)), note: '', hasAttachment: i % 3 === 0,
      status: i === 5 ? 'void' : 'posted', createdBy: i % 2 ? 'Fahim Rahman' : 'Sabbir Hossain', voidReason: i === 5 ? 'Entered twice' : undefined,
    }
  })
}
export const expenses = entries('EXP', expenseCategories, 40, 2400)
export const incomes = entries('INC', incomeCategories, 18, 3800)

export function balanceSheet(asOf: string): BalanceSheet {
  const cash = accounts.filter(a => a.type === 'cash').reduce((s, a) => s + a.balance, 0)
  const bank = accounts.filter(a => a.type === 'bank').reduce((s, a) => s + a.balance, 0)
  const wallet = accounts.filter(a => a.type === 'mobile_wallet').reduce((s, a) => s + a.balance, 0)
  const stock = 2_874_215.4
  const receivable = 31_202
  const payable = 358_550
  const assetsTotal = cash + bank + wallet + stock + receivable
  const from = new Date(asOf); from.setDate(1)
  return {
    asOf,
    assets: [
      { label: 'Cash in drawers', amount: cash },
      { label: 'Bank accounts', amount: bank },
      { label: 'Mobile wallets', amount: wallet, note: 'bKash, Nagad' },
      { label: 'Inventory at batch cost', amount: stock, note: 'Sellable + quarantined batches, excl. expired' },
      { label: 'Customer receivables', amount: receivable, note: 'Party ledger balances' },
    ],
    liabilities: [{ label: 'Supplier payables', amount: payable, note: 'Party ledger balances' }],
    equity: [
      { label: "Owner's capital", amount: 3_500_000 },
      { label: 'Retained earnings (balancing)', amount: r2(assetsTotal - payable - 3_500_000) },
    ],
    pnl: { from: from.toISOString().slice(0, 10), to: asOf, sales: 1_684_320.5, returns: 18_440, cogs: 1_402_118.2, expenses: 186_900, income: 22_350 },
  }
}
