/**
 * DEVELOPMENT-ONLY MOCK DATA: held sales, sale returns, online orders, riders,
 * shipping zones. Loaded only when runtimeConfig.public.useMocks is true.
 */
import { products, sales } from './seed'
import type { HeldSaleSummary, OnlineOrder, OrderHistory, OrderStatus, Rider, SaleReturnSummary, ShippingZone } from '~/types/orders'

function rng(seed: number) {
  return () => {
    seed |= 0; seed = (seed + 0x6D2B79F5) | 0
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}
const r = rng(4242)
const pick = <T>(a: T[]): T => a[Math.floor(r() * a.length)]!
const int = (min: number, max: number) => Math.floor(r() * (max - min + 1)) + min
const round2 = (n: number) => Math.round(n * 100) / 100
const now = Date.now()
const ago = (mins: number) => new Date(now - mins * 60000).toISOString()

export const heldSales: HeldSaleSummary[] = [
  { id: 'SRV-4411', heldAt: ago(38), heldBy: 'Moushumi', register: 'Counter 2', customer: 'Nusrat Islam', items: 4, total: 1265, source: 'server' },
  { id: 'SRV-4409', heldAt: ago(95), heldBy: 'Sabbir', register: 'Counter 1', customer: null, items: 2, total: 340, source: 'server' },
  { id: 'SRV-4402', heldAt: ago(260), heldBy: 'Moushumi', register: 'Counter 2', customer: 'Habib Rahman', items: 7, total: 3890.5, source: 'server' },
]

const reasons = ['Wrong strength dispensed', 'Doctor changed prescription', 'Duplicate purchase', 'Damaged pack', 'Customer allergic reaction', 'Near expiry, customer refused']
export const saleReturns: SaleReturnSummary[] = Array.from({ length: 34 }, (_, i) => {
  const s = sales[(i * 3 + 2) % sales.length]!
  const n = int(1, 3)
  const lines = Array.from({ length: n }, () => {
    const p = pick(products)
    const b = p.batches[0]!
    const unit = p.units.find(u => u.isSaleDefault) ?? p.units[0]!
    const qtyBase = unit.factor * int(1, 2)
    const unitPrice = round2(unit.price / unit.factor)
    const restock = r() > 0.25
    return { productName: `${p.name} ${p.strength ?? ''}`.trim(), batchNo: b.batchNo, expiryDate: b.expiryDate, qtyBase, unitPrice, lineTotal: round2(qtyBase * unitPrice), restock }
  })
  return {
    id: 2100 - i, number: `R-DHK1-2026-${String(2100 - i).padStart(5, '0')}`, date: ago(i * 610 + 40),
    saleId: s.id, saleNumber: s.number, customer: s.customer, items: lines.length,
    refundMethod: pick(['cash', 'cash', 'credit', 'bkash'] as const),
    total: round2(lines.reduce((a, l) => a + l.lineTotal, 0)),
    restockedQty: lines.filter(l => l.restock).reduce((a, l) => a + l.qtyBase, 0),
    writtenOffQty: lines.filter(l => !l.restock).reduce((a, l) => a + l.qtyBase, 0),
    reason: pick(reasons), createdBy: pick(['Sabbir', 'Moushumi', 'Admin']), lines,
  }
})

export const riders: Rider[] = [
  { id: 1, name: 'Jamal Uddin', phone: '01712345601', nid: '1990261548723', status: 'active', assignedOrders: 4 },
  { id: 2, name: 'Rubel Mia', phone: '01812345602', nid: '1992261548724', status: 'active', assignedOrders: 3 },
  { id: 3, name: 'Shakil Ahmed', phone: '01912345603', nid: '1995261548725', status: 'on_leave', assignedOrders: 0 },
  { id: 4, name: 'Masud Rana', phone: '01612345604', nid: '1988261548726', status: 'active', assignedOrders: 2 },
  { id: 5, name: 'Tareq Hasan', phone: '01512345605', nid: '1997261548727', status: 'inactive', assignedOrders: 0 },
]

const areas: [string, string, string, number][] = [
  ['Dhaka', 'Dhaka', 'Dhanmondi', 50], ['Dhaka', 'Dhaka', 'Mohammadpur', 60], ['Dhaka', 'Dhaka', 'Uttara', 70], ['Dhaka', 'Dhaka', 'Mirpur', 60],
  ['Dhaka', 'Dhaka', 'Gulshan', 70], ['Dhaka', 'Dhaka', 'Lalbagh', 60], ['Dhaka', 'Gazipur', 'Tongi', 100], ['Dhaka', 'Narayanganj', 'Fatullah', 100],
  ['Chattogram', 'Chattogram', 'Panchlaish', 130], ['Rajshahi', 'Rajshahi', 'Boalia', 130], ['Khulna', 'Khulna', 'Sonadanga', 130], ['Sylhet', 'Sylhet', 'Kotwali', 130],
]
export const zones: ShippingZone[] = areas.map(([division, district, thana, rate], i) => ({
  id: i + 1, division, district, thana, rate, freeShippingMin: rate <= 70 ? 1500 : null,
  partner: rate <= 70 ? 'own_rider' : pick(['pathao', 'steadfast', 'redx'] as const), isActive: i !== 7,
}))

const names = ['Farhana Akter', 'Tanvir Hossain', 'Sadia Rahman', 'Imran Chowdhury', 'Jannat Begum', 'Rakib Khan', 'Sharmin Sarker', 'Arif Uddin', 'Mitu Islam', 'Shuvo Ahmed', 'Tania Hossain', 'Habib Rahman']
const roads = ['Road 7A', 'Road 12', 'Lane 4', 'Block C, Road 3', 'Sector 11, Road 9', 'House 22, Road 2']
const flow: OrderStatus[] = ['pending', 'confirmed', 'packed', 'in_transit', 'delivered']
const statusPlan: OrderStatus[] = ['pending', 'pending', 'pending', 'pending', 'pending', 'confirmed', 'confirmed', 'confirmed', 'confirmed', 'packed', 'packed', 'packed', 'in_transit', 'in_transit', 'in_transit', 'in_transit', 'delivered', 'delivered', 'delivered', 'delivered', 'delivered', 'delivered', 'cancelled', 'cancelled']

export const orders: OnlineOrder[] = statusPlan.map((status, i) => {
  const [division, district, thana, rate] = areas[i % areas.length]!
  const n = int(1, 4)
  const lines = Array.from({ length: n }, () => {
    const p = pick(products)
    const unit = p.units.find(u => u.isSaleDefault) ?? p.units[0]!
    const qty = int(1, 3)
    const allocated = status !== 'pending' && status !== 'cancelled'
    return { productName: `${p.name} ${p.strength ?? ''}`.trim(), qty, unitLabel: unit.label, unitPrice: unit.price, lineTotal: round2(qty * unit.price), batchNo: allocated ? p.batches[0]!.batchNo : null }
  })
  const subtotal = round2(lines.reduce((a, l) => a + l.lineTotal, 0))
  const shipping = subtotal >= 1500 && rate <= 70 ? 0 : rate
  const placedMinsAgo = 30 + i * 47
  const history: OrderHistory[] = [{ status: 'pending', at: ago(placedMinsAgo), by: 'Website' }]
  if (status === 'cancelled') history.push({ status: 'cancelled', at: ago(placedMinsAgo - 15), by: 'Admin', note: 'Customer unreachable by phone' })
  else {
    const steps = flow.indexOf(status)
    for (let s = 1; s <= steps; s++) history.push({ status: flow[s]!, at: ago(placedMinsAgo - s * 9), by: s === 1 ? 'Sabbir' : s >= 3 ? 'Rider' : 'Moushumi' })
  }
  const assigned = ['packed', 'in_transit', 'delivered'].includes(status) && rate <= 70
  const rider = assigned ? riders[i % 2 === 0 ? 0 : 1]! : null
  return {
    id: 5400 + statusPlan.length - i, number: `W-2026-${String(5400 + statusPlan.length - i).padStart(5, '0')}`,
    legacyRef: `#${119600 + i}`, trackingCode: `TRK-${String(80000 + i * 137).slice(0, 5)}`,
    channel: i % 3 === 0 ? 'app' : 'website', placedAt: ago(placedMinsAgo), status,
    customer: names[i % names.length]!, phone: `01${pick(['3', '5', '7', '8', '9'])}${String(int(10000000, 99999999))}`,
    address: `House ${int(3, 88)}, ${pick(roads)}`, division, district, thana, zone: `${thana}, ${district}`,
    items: lines.length, subtotal, shipping, codAmount: round2(subtotal + shipping),
    riderId: rider?.id ?? null, riderName: rider?.name ?? null, lines, history,
  }
})
