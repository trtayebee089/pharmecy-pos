const bdt = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const int = new Intl.NumberFormat('en-IN')

/** ৳ 1,23,456.00 (Bangladeshi digit grouping) */
export function money(value: number | null | undefined, symbol = true): string {
  const v = Number(value ?? 0)
  return `${symbol ? '৳ ' : ''}${bdt.format(v)}`
}

export function num(value: number | null | undefined): string {
  return int.format(Number(value ?? 0))
}

export function compactMoney(value: number): string {
  if (Math.abs(value) >= 1e7) return `৳ ${(value / 1e7).toFixed(2)} Cr`
  if (Math.abs(value) >= 1e5) return `৳ ${(value / 1e5).toFixed(2)} L`
  if (Math.abs(value) >= 1e3) return `৳ ${(value / 1e3).toFixed(1)}k`
  return money(value)
}

export function date(iso: string | null | undefined, opts: Intl.DateTimeFormatOptions = { day: '2-digit', month: 'short', year: 'numeric' }): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('en-GB', opts)
}

export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Whole days from today until the given date (negative = past). */
export function daysUntil(iso: string): number {
  const d = new Date(iso)
  const t = new Date()
  d.setHours(0, 0, 0, 0)
  t.setHours(0, 0, 0, 0)
  return Math.round((d.getTime() - t.getTime()) / 86_400_000)
}

export type ExpiryState = 'expired' | 'critical' | 'soon' | 'ok'

/** Expiry thresholds come from settings in the real system (near-expiry days). */
export function expiryState(iso: string, soonDays = 90, criticalDays = 30): ExpiryState {
  const d = daysUntil(iso)
  if (d < 0) return 'expired'
  if (d <= criticalDays) return 'critical'
  if (d <= soonDays) return 'soon'
  return 'ok'
}

/** Breaks a piece quantity into the largest units, e.g. 245 pcs → "1 box · 4 strip · 5 pc". */
export function unitBreakdown(qtyBase: number, units: { code: string; factor: number }[]): string {
  const sorted = [...units].sort((a, b) => b.factor - a.factor)
  let rest = qtyBase
  const parts: string[] = []
  for (const u of sorted) {
    const n = Math.floor(rest / u.factor)
    if (n > 0) {
      parts.push(`${n} ${u.code === 'piece' ? 'pc' : u.code}`)
      rest -= n * u.factor
    }
  }
  return parts.length ? parts.join(' · ') : '0'
}

export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]?.toUpperCase()).join('')
}
