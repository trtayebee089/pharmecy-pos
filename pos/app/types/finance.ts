// Finance types (mirror planned /api/pos/v1 contract).

export type AccountType = 'cash' | 'bank' | 'mobile_wallet'

export interface Account {
  id: number
  code: string
  name: string
  type: AccountType
  accountNo: string
  openingBalance: number
  balance: number // derived by the API from account_transactions
  isDefault: boolean
  isActive: boolean
}

export interface StatementLine { date: string; description: string; ref: string; inAmount: number; outAmount: number; balance: number }

export interface MoneyTransfer {
  id: number
  number: string
  date: string
  fromAccount: string
  toAccount: string
  amount: number
  note: string
  createdBy: string
}

export interface FinanceCategory { id: number; name: string; parent: string; entries: number; isActive: boolean }

export interface FinanceEntry {
  id: number
  number: string
  date: string
  category: string
  account: string
  amount: number
  note: string
  hasAttachment: boolean
  status: 'posted' | 'void'
  createdBy: string
  voidReason?: string
}

export interface BalanceSheet {
  asOf: string
  assets: { label: string; amount: number; note?: string }[]
  liabilities: { label: string; amount: number; note?: string }[]
  equity: { label: string; amount: number; note?: string }[]
  pnl: { from: string; to: string; sales: number; returns: number; cogs: number; expenses: number; income: number }
}
