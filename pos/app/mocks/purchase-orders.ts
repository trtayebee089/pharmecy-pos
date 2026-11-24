/**
 * DEVELOPMENT-ONLY MOCK DATA for purchase orders by company.
 * Loaded only when runtimeConfig.public.useMocks is true.
 */
import { products } from './seed'
import type { AiRun, Company, DraftDetail, DraftSummary, OrderForecast, OrderRow } from '~/types/purchase-orders'

const r2 = (n: number) => Math.round(n * 100) / 100
const daysBetween = (from: string, to: string) => Math.max(1, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 864e5) + 1)

export function companies(): Company[] {
  const names = [...new Set(products.map(p => p.manufacturer))].sort()
  return names.map((name, i) => ({ id: i + 1, name, products: products.filter(p => p.manufacturer === name).length }))
}
const companyName = (id: number) => companies().find(c => c.id === id)?.name ?? ''

/** Deterministic daily demand per product (pieces/day). */
const dailyDemand = (id: number, perStrip: number) => Math.max(0.5, ((id * 37) % 13 + 2) * perStrip / 10)

export function orderRows(companyId: number, from: string, to: string): OrderRow[] {
  const name = companyName(companyId)
  const days = daysBetween(from, to)
  return products.filter(p => p.manufacturer === name).map((p) => {
    const strip = p.units.find(u => u.code === 'strip')?.factor ?? 1
    const box = p.units.find(u => u.code === 'box')?.factor ?? strip
    const piecesPerStrip = Math.max(1, strip)
    const stripsPerBox = Math.max(1, Math.round(box / piecesPerStrip))
    return {
      productId: p.id, code: p.sku, name: p.name, strength: p.strength, category: p.dosageForm,
      soldQty: Math.round(dailyDemand(p.id, piecesPerStrip) * days), inStock: p.stockBase,
      piecesPerStrip, stripsPerBox, boxQty: 0, stripQty: 0, orderQty: 0,
      unitCost: r2(p.batches[p.batches.length - 1]?.unitCost ?? 1),
    }
  })
}

export function forecasts(productIds: number[]): Record<number, OrderForecast> {
  const out: Record<number, OrderForecast> = {}
  for (const id of productIds) {
    const p = products.find(x => x.id === id)
    if (!p) continue
    const strip = p.units.find(u => u.code === 'strip')?.factor ?? 1
    const daily = dailyDemand(id, strip)
    const trend = 1 + (((id * 11) % 7) - 3) / 20 // −15% … +15%
    const forecast7 = Math.round(daily * 7 * trend)
    const need = Math.max(0, Math.round(daily * trend * 21) - p.stockBase) // 3 weeks of cover
    const conf: OrderForecast['confidence'] = id % 3 === 0 ? 'medium' : id % 5 === 0 ? 'low' : 'high'
    out[id] = {
      forecast7, recommended: need, confidence: conf,
      reason: need > 0
        ? `About ${Math.round(daily * trend)} pcs/day expected (${trend >= 1 ? '+' : ''}${Math.round((trend - 1) * 100)}% trend). ${p.stockBase} pcs covers ${Math.floor(p.stockBase / Math.max(daily * trend, 0.1))} days; ordering tops up to 21 days.`
        : `Stock of ${p.stockBase} pcs covers more than 21 days at ~${Math.round(daily * trend)} pcs/day. No order needed.`,
    }
  }
  return out
}

let seq = 6
const drafts: DraftDetail[] = [3, 7, 12].map((companyId, i) => {
  const d = new Date(); d.setDate(d.getDate() - i * 2)
  const date = d.toISOString().slice(0, 10)
  const rows = orderRows(companyId, date, date).slice(0, 6).map((r, j) => {
    const boxQty = 1 + ((r.productId + j) % 3)
    return { ...r, boxQty, stripQty: boxQty * r.stripsPerBox, orderQty: boxQty * r.stripsPerBox * r.piecesPerStrip }
  })
  return summarize({ id: i + 1, number: `PO-2026-${String(41 + i).padStart(4, '0')}`, companyId, company: companyName(companyId), date, source: i === 1 ? 'ai' : 'manual', status: 'draft', createdBy: i === 1 ? 'AI draft' : 'Rakib Uddin', rows })
})

function summarize(d: Omit<DraftDetail, 'lines' | 'orderQty' | 'estValue'> & Partial<DraftDetail>): DraftDetail {
  const rows = d.rows.filter(r => r.orderQty > 0)
  return { ...d, rows, lines: rows.length, orderQty: rows.reduce((s, r) => s + r.orderQty, 0), estValue: r2(rows.reduce((s, r) => s + r.orderQty * r.unitCost, 0)) } as DraftDetail
}

export function listDrafts(f: { from?: string; to?: string; companyId?: number; status?: string }): DraftSummary[] {
  return drafts
    .filter(d => (!f.companyId || d.companyId === f.companyId) && (!f.from || d.date >= f.from) && (!f.to || d.date <= f.to) && (!f.status || d.status === f.status))
    .map(({ rows: _rows, ...s }) => s)
}
export const getDraft = (id: number) => structuredClone(drafts.find(d => d.id === id) ?? null)

export function saveDraft(companyId: number, date: string, rows: OrderRow[], source: 'manual' | 'ai', by: string): DraftDetail {
  // Same company + date updates the existing draft (legacy behaviour), otherwise creates one.
  const existing = drafts.find(d => d.companyId === companyId && d.date === date && d.status === 'draft')
  const next = summarize({ id: existing?.id ?? ++seq, number: existing?.number ?? `PO-2026-${String(40 + seq).padStart(4, '0')}`, companyId, company: companyName(companyId), date, source, status: 'draft', createdBy: by, rows })
  if (existing) drafts.splice(drafts.indexOf(existing), 1, next)
  else drafts.unshift(next)
  return next
}
export function updateDraft(id: number, rows: OrderRow[]) {
  const i = drafts.findIndex(d => d.id === id)
  if (i >= 0) drafts[i] = summarize({ ...drafts[i]!, rows })
  return drafts[i]
}
export function deleteDraft(id: number) {
  const i = drafts.findIndex(d => d.id === id)
  if (i >= 0) drafts.splice(i, 1)
}
export function convertDraft(id: number, supplier: string): { purchaseId: number; number: string } {
  const d = drafts.find(x => x.id === id)!
  const purchaseId = 8700 + id
  d.status = 'converted'
  d.purchaseNumber = `P-DHK1-2026-${purchaseId}`
  return { purchaseId, number: `${d.purchaseNumber} (${supplier})` }
}

// Global AI draft generation: one draft per company with a shortage.
const runs = new Map<number, AiRun & { queue: number[] }>()
export function startRun(): AiRun {
  const queue = companies().map(c => c.id)
  const run = { id: Date.now(), total: queue.length, processed: 0, created: 0, status: 'running' as const, queue }
  runs.set(run.id, run)
  return { ...run }
}
export function processRun(id: number, batch = 3): AiRun {
  const run = runs.get(id)!
  const today = new Date().toISOString().slice(0, 10)
  for (const companyId of run.queue.splice(0, batch)) {
    const rows = orderRows(companyId, today, today)
    const f = forecasts(rows.map(r => r.productId))
    const filled = rows.map((r) => {
      const need = f[r.productId]?.recommended ?? 0
      const perBox = r.piecesPerStrip * r.stripsPerBox
      const boxQty = need > 0 ? Math.ceil(need / perBox) : 0
      return { ...r, forecast: f[r.productId], boxQty, stripQty: boxQty * r.stripsPerBox, orderQty: boxQty * perBox }
    })
    if (filled.some(r => r.orderQty > 0)) { saveDraft(companyId, today, filled, 'ai', 'AI draft'); run.created++ }
    run.processed++
  }
  if (!run.queue.length) (run as AiRun).status = 'done'
  return { ...run }
}
