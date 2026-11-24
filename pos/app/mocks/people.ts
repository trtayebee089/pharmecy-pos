/**
 * DEVELOPMENT-ONLY MOCK DATA: customer groups, dues, customer profiles,
 * reward wallets/ledger, campaigns, membership plans and members.
 * Loaded only when runtimeConfig.public.useMocks is true.
 */
import { customers, sales } from './seed'
import type {
  Campaign, CustomerGroup, CustomerProfile, DueCustomer, LedgerEntry, Member, MembershipPlan, OpenInvoice, RewardEntry, RewardWallet, Tier,
} from '~/types/people'

const DAY = 864e5
const iso = (msAgo: number) => new Date(Date.now() - msAgo).toISOString()
const r2 = (n: number) => Math.round(n * 100) / 100
const named = customers.filter(c => !c.isWalkIn)

export const groups: CustomerGroup[] = [
  { id: 1, name: 'Regular patient', discountPct: 5, members: named.filter(c => c.group === 'Regular patient').length, notes: 'Chronic medication buyers with monthly refills' },
  { id: 2, name: 'Senior citizen', discountPct: 7, members: 6, notes: 'Age 60+, verified by NID' },
  { id: 3, name: 'Doctor / clinic', discountPct: 10, members: 4, notes: 'Registered practitioners buying for chambers' },
  { id: 4, name: 'Corporate', discountPct: 3, members: 2, notes: 'Monthly credit, invoice on the 1st' },
]

/** Open invoices that make up each customer's due, oldest first. Sums equal dueBalance. */
export function openInvoices(customerId: number): OpenInvoice[] {
  const c = customers.find(x => x.id === customerId)
  if (!c || !c.dueBalance) return []
  const n = 1 + (customerId % 3)
  const parts: OpenInvoice[] = []
  let rest = c.dueBalance
  for (let i = 0; i < n; i++) {
    const due = i === n - 1 ? rest : r2(Math.floor((c.dueBalance / n) * 100) / 100)
    rest = r2(rest - due)
    const total = r2(due + (i === 0 ? 0 : 150 * (i + 1)))
    const id = 119000 + customerId * 7 + i
    parts.push({ saleId: id, number: `S-DHK1-2026-${String(id).padStart(6, '0')}`, date: iso((60 - i * 18 - (customerId % 9)) * DAY), total, paid: r2(total - due), due })
  }
  return parts
}

export function dueCustomers(): DueCustomer[] {
  return named.filter(c => c.dueBalance > 0).map((c) => {
    const inv = openInvoices(c.id)
    return {
      id: c.id, name: c.name, phone: c.phone, dueBalance: c.dueBalance, openInvoices: inv.length,
      oldestDays: Math.round((Date.now() - new Date(inv[0]!.date).getTime()) / DAY),
      lastPaymentAt: c.id % 4 ? iso((c.id % 20) * DAY) : null,
    }
  }).sort((a, b) => b.dueBalance - a.dueBalance)
}

function rewardEntries(customerId: number, balance: number): RewardEntry[] {
  const out: RewardEntry[] = [{ id: customerId * 100, date: iso(240 * DAY), type: 'opening', points: Math.round(balance * 0.4), ref: 'Legacy balance', note: 'Migrated from legacy points' }]
  let acc = out[0]!.points
  for (let i = 0; i < 6; i++) {
    const t: RewardEntry['type'] = i === 3 ? 'redeem' : i === 5 && customerId % 3 === 0 ? 'expire' : 'earn'
    const pts = t === 'earn' ? 20 + ((customerId * (i + 3)) % 80) : -(10 + ((customerId + i) % 40))
    acc += pts
    out.push({ id: customerId * 100 + i + 1, date: iso((200 - i * 32) * DAY), type: t, points: pts, ref: t === 'earn' ? `S-DHK1-2026-${119500 + customerId + i}` : t === 'redeem' ? `S-DHK1-2026-${119600 + customerId}` : 'Expiry run', note: t === 'expire' ? '12-month expiry' : '' })
  }
  const diff = balance - acc
  if (diff) out.push({ id: customerId * 100 + 9, date: iso(4 * DAY), type: diff > 0 ? 'earn' : 'adjust', points: diff, ref: diff > 0 ? `S-DHK1-2026-${120100 + customerId}` : 'ADJ', note: diff > 0 ? '' : 'Manual correction' })
  return out.reverse()
}

export function profile(id: number): CustomerProfile | null {
  const c = customers.find(x => x.id === id)
  if (!c) return null
  const own = sales.filter((s, i) => s.customer === c.name || (!c.isWalkIn && i % 41 === id % 41)).slice(0, 12)
  const inv = openInvoices(id)
  let bal = 0
  const ledger: LedgerEntry[] = []
  for (const s of own.slice().reverse()) {
    bal = r2(bal + s.grandTotal); ledger.push({ date: s.date, type: 'invoice', ref: s.number, debit: s.grandTotal, credit: 0, balance: bal })
    bal = r2(bal - s.grandTotal); ledger.push({ date: s.date, type: 'payment', ref: `PAY-${s.id}`, debit: 0, credit: s.grandTotal, balance: bal })
  }
  for (const i of inv) {
    bal = r2(bal + i.total); ledger.push({ date: i.date, type: 'invoice', ref: i.number, debit: i.total, credit: 0, balance: bal })
    if (i.paid) { bal = r2(bal - i.paid); ledger.push({ date: i.date, type: 'payment', ref: `PAY-${i.saleId}`, debit: 0, credit: i.paid, balance: bal }) }
  }
  ledger.sort((a, b) => a.date.localeCompare(b.date))
  let run = 0
  for (const l of ledger) { run = r2(run + l.debit - l.credit); l.balance = run }
  return {
    id: c.id, code: c.code, name: c.name, phone: c.phone, email: c.isWalkIn ? null : `${c.name.split(' ')[0]!.toLowerCase()}@example.test`,
    address: c.isWalkIn ? null : ['Dhanmondi 27, Dhaka', 'Mohammadpur, Dhaka', 'Uttara Sector 7, Dhaka', 'Lalmatia, Dhaka'][id % 4]!,
    group: c.group, membership: c.membership, points: c.points, dueBalance: c.dueBalance, totalSpent: c.totalSpent,
    visits: 6 + (id * 7) % 40, avgBasket: r2(c.totalSpent / (6 + (id * 7) % 40)), firstVisit: iso((300 + id) * DAY), lastVisit: c.lastVisit,
    purchases: own.map(s => ({ id: s.id, number: s.number, date: s.date, items: s.items, total: s.grandTotal, status: s.status })),
    ledger: ledger.reverse(),
    rewards: rewardEntries(c.id, c.points),
  }
}

const tierOf = (spent: number): Tier => spent > 80000 ? 'Platinum' : spent > 60000 ? 'Gold' : spent > 25000 ? 'Silver' : null

export function wallets(): RewardWallet[] {
  return named.map((c) => {
    const entries = rewardEntries(c.id, c.points)
    return {
      id: c.id, customerId: c.id, customer: c.name, phone: c.phone, balance: c.points,
      lifetimeEarned: entries.filter(e => e.points > 0).reduce((s, e) => s + e.points, 0),
      lifetimeRedeemed: -entries.filter(e => e.type === 'redeem').reduce((s, e) => s + e.points, 0),
      tier: tierOf(c.totalSpent), expiringSoon: c.id % 5 === 0 ? Math.min(c.points, 40) : 0, updatedAt: c.lastVisit ?? iso(DAY),
    }
  }).sort((a, b) => b.balance - a.balance)
}

export function walletLedger(customerId: number): RewardEntry[] {
  const c = customers.find(x => x.id === customerId)
  return c ? rewardEntries(c.id, c.points) : []
}

export const campaigns: Campaign[] = [
  { id: 1, name: 'Winter wellness double points', multiplier: 2, startsAt: iso(-2 * DAY).slice(0, 10), endsAt: iso(-40 * DAY).slice(0, 10), isActive: true, description: 'Vitamins, cough & cold range earn 2× points' },
  { id: 2, name: 'Diabetes care month', multiplier: 1.5, startsAt: iso(10 * DAY).slice(0, 10), endsAt: iso(-20 * DAY).slice(0, 10), isActive: true, description: 'Antidiabetic medicines and glucose strips' },
  { id: 3, name: 'Eid special', multiplier: 3, startsAt: iso(200 * DAY).slice(0, 10), endsAt: iso(190 * DAY).slice(0, 10), isActive: false, description: 'All purchases above ৳1,000' },
]

export const plans: MembershipPlan[] = [
  { id: 1, code: 'Silver', name: 'Silver Care', minAnnualSpend: 25000, discountPct: 2, pointsMultiplier: 1.25, fee: 0, durationMonths: 12, benefits: ['2% off every invoice', '1.25× loyalty points', 'Refill reminders by SMS'], isActive: true, members: named.filter(c => c.membership === 'Silver').length },
  { id: 2, code: 'Gold', name: 'Gold Care', minAnnualSpend: 60000, discountPct: 4, pointsMultiplier: 1.5, fee: 500, durationMonths: 12, benefits: ['4% off every invoice', '1.5× loyalty points', 'Free home delivery in zone', 'Priority counter service'], isActive: true, members: named.filter(c => c.membership === 'Gold').length },
  { id: 3, code: 'Platinum', name: 'Platinum Family', minAnnualSpend: 120000, discountPct: 6, pointsMultiplier: 2, fee: 1500, durationMonths: 12, benefits: ['6% off for up to 4 family members', '2× loyalty points', 'Free delivery anywhere in Dhaka', 'Dedicated pharmacist line', 'Annual health check voucher'], isActive: true, members: 3 },
]

export function members(): Member[] {
  return named.filter(c => c.membership).map((c, i) => {
    const endDays = [-12, 5, 21, 70, 140, 200, 300][i % 7]!
    return {
      id: c.id, customer: c.name, phone: c.phone, plan: c.membership as Member['plan'],
      startsAt: iso((365 - endDays) * DAY), endsAt: iso(-endDays * DAY),
      status: endDays < 0 ? 'expired' : 'active', source: (['auto', 'purchase', 'manual'] as const)[i % 3]!,
    }
  })
}
