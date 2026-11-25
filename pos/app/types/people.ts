// People & loyalty types (mirror planned /api/pos/v1 contract).

export interface CustomerGroup { id: number; name: string; discountPct: number; members: number; notes: string }

export interface OpenInvoice { saleId: number; number: string; date: string; total: number; paid: number; due: number }

export interface DueCustomer {
  id: number
  name: string
  phone: string | null
  dueBalance: number
  openInvoices: number
  oldestDays: number
  lastPaymentAt: string | null
}

export interface LedgerEntry { date: string; type: 'opening' | 'invoice' | 'payment' | 'return' | 'refund' | 'adjustment' | 'membership' | 'void'; ref: string; debit: number; credit: number; balance: number }

export interface CustomerProfile {
  id: number
  code: string
  name: string
  phone: string | null
  email: string | null
  address: string | null
  group: string | null
  membership: string | null
  points: number
  dueBalance: number
  totalSpent: number
  visits: number
  avgBasket: number
  firstVisit: string | null
  lastVisit: string | null
  purchases: { id: number; number: string; date: string; items: number; total: number; status: string }[]
  ledger: LedgerEntry[]
  rewards: RewardEntry[]
}

export type Tier = 'Silver' | 'Gold' | 'Platinum' | null

export interface RewardWallet {
  id: number
  customerId: number
  customer: string
  phone: string | null
  balance: number
  lifetimeEarned: number
  lifetimeRedeemed: number
  tier: Tier
  expiringSoon: number
  updatedAt: string
}

export interface RewardEntry { id: number; date: string; type: 'opening' | 'earn' | 'redeem' | 'expire' | 'adjust' | 'reverse'; points: number; ref: string; note: string }

export interface Campaign { id: number; name: string; multiplier: number; startsAt: string; endsAt: string; isActive: boolean; description: string }

export interface MembershipPlan {
  id: number
  code: 'Silver' | 'Gold' | 'Platinum'
  name: string
  minAnnualSpend: number
  discountPct: number
  pointsMultiplier: number
  fee: number
  durationMonths: number
  benefits: string[]
  isActive: boolean
  members: number
}

export interface Member {
  id: number
  customer: string
  phone: string | null
  plan: 'Silver' | 'Gold' | 'Platinum'
  startsAt: string
  endsAt: string
  status: 'active' | 'expired' | 'cancelled'
  source: 'auto' | 'purchase' | 'manual'
}
