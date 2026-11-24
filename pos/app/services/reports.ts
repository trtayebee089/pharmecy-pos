/**
 * Report endpoints (/api/pos/v1/reports/*). Figures are computed server-side;
 * with useMocks on (development only) they are derived from the mock seed.
 */
import type { DateRange, ReportResult } from '~/types/domain'

export interface ReportParams { range: DateRange; filters: Record<string, string> }

const seed = () => import('~/mocks/seed')
const extra = () => import('~/mocks/extra')
const r2 = (n: number) => Math.round(n * 100) / 100
const inRange = (iso: string, r: DateRange) => { const d = iso.slice(0, 10); return d >= r.from && d <= r.to }
const sum = <T>(rows: T[], f: (r: T) => number) => r2(rows.reduce((s, r) => s + f(r), 0))
/** Small deterministic hash for stable mock splits. */
const h = (s: string) => [...s].reduce((a, c) => (a * 31 + c.charCodeAt(0)) >>> 0, 7)

function byDay<T>(rows: T[], dateOf: (r: T) => string, value: (r: T) => number) {
  const m = new Map<string, number>()
  for (const r of [...rows].sort((a, b) => dateOf(a).localeCompare(dateOf(b)))) {
    const k = date(dateOf(r), { day: '2-digit', month: 'short' })
    m.set(k, r2((m.get(k) ?? 0) + value(r)))
  }
  return [...m.entries()].map(([label, value]) => ({ label, value }))
}

export function useReportsApi() {
  const api = useApi()
  const real = (path: string, p: ReportParams) => api.get<ReportResult>(`/reports/${path}`, { ...p.range, ...p.filters })

  return {
    async profit(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('profit', p)
      await mockDelay(450)
      const { sales, products } = await seed()
      const view = p.filters.view || 'day'
      const done = sales.filter(s => s.status === 'completed' && inRange(s.date, p.range))
      // COGS ratio per sale from batch cost (mock: 78–86% of revenue)
      const cogsOf = (s: { id: number; grandTotal: number }) => r2(s.grandTotal * (0.78 + (s.id % 9) / 100))
      const revenue = sum(done, s => s.grandTotal)
      const cogs = sum(done, cogsOf)
      const expenses = r2(revenue * 0.045)
      const columns = view === 'product'
        ? [
            { key: 'product', label: 'Product' }, { key: 'qty', label: 'Pieces sold', align: 'right' as const, format: 'number' as const },
            { key: 'revenue', label: 'Revenue', align: 'right' as const, format: 'money' as const }, { key: 'cogs', label: 'COGS (batch cost)', align: 'right' as const, format: 'money' as const },
            { key: 'profit', label: 'Gross profit', align: 'right' as const, format: 'money' as const }, { key: 'margin', label: 'Margin', align: 'right' as const, format: 'percent' as const },
          ]
        : [
            { key: 'day', label: 'Date', format: 'date' as const }, { key: 'invoices', label: 'Invoices', align: 'right' as const, format: 'number' as const },
            { key: 'revenue', label: 'Revenue', align: 'right' as const, format: 'money' as const }, { key: 'cogs', label: 'COGS (batch cost)', align: 'right' as const, format: 'money' as const },
            { key: 'profit', label: 'Gross profit', align: 'right' as const, format: 'money' as const }, { key: 'margin', label: 'Margin', align: 'right' as const, format: 'percent' as const },
          ]
      let rows: Record<string, any>[]
      if (view === 'product') {
        const share = products.map(pr => ({ pr, w: (h(pr.name) % 97) + 5 }))
        const tw = share.reduce((s, x) => s + x.w, 0)
        rows = share.map(({ pr, w }) => {
          const rev = r2(revenue * w / tw)
          const c = r2(rev * (0.74 + (h(pr.sku) % 14) / 100))
          return { product: `${pr.name} ${pr.strength ?? ''}`.trim(), qty: Math.round(rev / (pr.units[0]!.price || 1)), revenue: rev, cogs: c, profit: r2(rev - c), margin: rev ? ((rev - c) / rev) * 100 : 0 }
        }).sort((a, b) => b.profit - a.profit)
      }
      else {
        const m = new Map<string, Record<string, any>>()
        for (const s of done) {
          const k = s.date.slice(0, 10)
          const row = m.get(k) ?? { day: k, invoices: 0, revenue: 0, cogs: 0, profit: 0, margin: 0 }
          row.invoices++; row.revenue = r2(row.revenue + s.grandTotal); row.cogs = r2(row.cogs + cogsOf(s))
          m.set(k, row)
        }
        rows = [...m.values()].map((r): Record<string, any> => ({ ...r, profit: r2(r.revenue - r.cogs), margin: r.revenue ? ((r.revenue - r.cogs) / r.revenue) * 100 : 0 })).sort((a, b) => String(b.day).localeCompare(String(a.day)))
      }
      const gross = r2(revenue - cogs)
      return {
        kpis: [
          { label: 'Revenue', value: revenue, format: 'money' },
          { label: 'Gross profit', value: gross, format: 'money', hint: `${revenue ? ((gross / revenue) * 100).toFixed(1) : 0}% margin` },
          { label: 'Expenses', value: expenses, format: 'money', hint: 'Posted expenses in period' },
          { label: 'Net profit', value: r2(gross - expenses), format: 'money' },
        ],
        series: byDay(done, s => s.date, s => r2(s.grandTotal - cogsOf(s))),
        seriesTitle: 'Gross profit per day',
        columns, rows,
        totals: { invoices: done.length, qty: sum(rows, r => r.qty ?? 0), revenue, cogs: sum(rows, r => r.cogs), profit: sum(rows, r => r.profit) },
      }
    },

    async stock(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('stock', p)
      await mockDelay(400)
      const { products } = await seed()
      const rows = products
        .filter(pr => (!p.filters.manufacturer || pr.manufacturer === p.filters.manufacturer) && (!p.filters.form || pr.dosageForm === p.filters.form))
        .map((pr) => {
          const cost = sum(pr.batches, b => b.qtyBase * b.unitCost)
          const retail = r2(pr.stockBase * pr.units[0]!.price / pr.units[0]!.factor)
          return {
            productId: pr.id, product: `${pr.name} ${pr.strength ?? ''}`.trim(), manufacturer: pr.manufacturer, form: pr.dosageForm,
            batches: pr.batches.length, pieces: pr.stockBase, reorder: pr.reorderLevelBase,
            status: pr.stockBase <= 0 ? 'out' : pr.stockBase <= pr.reorderLevelBase ? 'low' : 'ok',
            costValue: cost, retailValue: retail,
          }
        })
      const cost = sum(rows, r => r.costValue)
      const retail = sum(rows, r => r.retailValue)
      return {
        kpis: [
          { label: 'Stock value at cost', value: cost, format: 'money' },
          { label: 'Stock value at retail', value: retail, format: 'money', hint: `Potential margin ${money(retail - cost)}` },
          { label: 'Sellable pieces', value: sum(rows, r => r.pieces), format: 'number' },
          { label: 'Low / out of stock', value: rows.filter(r => r.status !== 'ok').length, format: 'number', hint: 'At or below reorder level' },
        ],
        columns: [
          { key: 'product', label: 'Product' }, { key: 'manufacturer', label: 'Manufacturer' }, { key: 'form', label: 'Form' },
          { key: 'batches', label: 'Batches', align: 'right', format: 'number' }, { key: 'pieces', label: 'Pieces', align: 'right', format: 'number' },
          { key: 'reorder', label: 'Reorder at', align: 'right', format: 'number' },
          { key: 'status', label: 'Status', format: 'badge', tones: { ok: 'success', low: 'warning', out: 'danger' } },
          { key: 'costValue', label: 'Value at cost', align: 'right', format: 'money' }, { key: 'retailValue', label: 'Value at retail', align: 'right', format: 'money' },
        ],
        rows, totals: { batches: sum(rows, r => r.batches), pieces: sum(rows, r => r.pieces), costValue: cost, retailValue: retail },
      }
    },

    async expiry(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('expiry', p)
      await mockDelay(400)
      const { batchRows } = await seed()
      const bucket = (d: number) => d < 0 ? 'expired' : d <= 30 ? 'critical' : d <= 90 ? 'soon' : 'ok'
      const all = batchRows.map(b => ({ ...b, days: daysUntil(b.expiryDate), bucket: bucket(daysUntil(b.expiryDate)) }))
      const want = p.filters.window
      const rows = all
        .filter(b => want ? b.bucket === want : b.bucket !== 'ok')
        .filter(b => !p.filters.manufacturer || b.manufacturer === p.filters.manufacturer)
        .sort((a, b) => a.days - b.days)
        .map(b => ({ productId: b.productId, batchId: b.batchId, product: b.productName, manufacturer: b.manufacturer, batchNo: b.batchNo, expiryDate: b.expiryDate, days: b.days, bucket: b.bucket, qty: b.qtyBase, value: b.value }))
      const val = (k: string) => sum(all.filter(b => b.bucket === k), b => b.value)
      return {
        kpis: [
          { label: 'Expired (blocked)', value: val('expired'), format: 'money', hint: `${all.filter(b => b.bucket === 'expired').length} batches · write off or return` },
          { label: 'Expiring ≤ 30 days', value: val('critical'), format: 'money', hint: `${all.filter(b => b.bucket === 'critical').length} batches` },
          { label: 'Expiring 31–90 days', value: val('soon'), format: 'money', hint: `${all.filter(b => b.bucket === 'soon').length} batches` },
          { label: 'Value at risk', value: r2(val('expired') + val('critical') + val('soon')), format: 'money', hint: 'At cost' },
        ],
        columns: [
          { key: 'product', label: 'Product' }, { key: 'manufacturer', label: 'Manufacturer' }, { key: 'batchNo', label: 'Batch', format: 'mono' },
          { key: 'expiryDate', label: 'Expiry', format: 'date' }, { key: 'days', label: 'Days left', align: 'right', format: 'number' },
          { key: 'bucket', label: 'Window', format: 'badge', tones: { expired: 'danger', critical: 'danger', soon: 'warning', ok: 'success' } },
          { key: 'qty', label: 'Pieces', align: 'right', format: 'number' }, { key: 'value', label: 'Value at cost', align: 'right', format: 'money' },
        ],
        rows, totals: { qty: sum(rows, r => r.qty), value: sum(rows, r => r.value) },
      }
    },

    async purchases(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('purchases', p)
      await mockDelay(400)
      const { purchases } = await seed()
      const list = purchases.filter(x => x.status === 'received' && inRange(x.date, p.range) && (!p.filters.supplier || x.supplier === p.filters.supplier))
      const bySupplier = p.filters.view === 'supplier'
      const rows = bySupplier
        ? [...new Set(list.map(x => x.supplier))].map((s) => {
            const g = list.filter(x => x.supplier === s)
            return { supplier: s, invoices: g.length, lines: sum(g, x => x.items), grandTotal: sum(g, x => x.grandTotal), paidTotal: sum(g, x => x.paidTotal), dueTotal: sum(g, x => x.dueTotal) }
          }).sort((a, b) => b.grandTotal - a.grandTotal)
        : list.map(x => ({ id: x.id, number: x.number, date: x.date, supplier: x.supplier, invoiceNo: x.supplierInvoiceNo, lines: x.items, grandTotal: x.grandTotal, paidTotal: x.paidTotal, dueTotal: x.dueTotal }))
      const total = sum(list, x => x.grandTotal)
      return {
        kpis: [
          { label: 'Purchased', value: total, format: 'money' },
          { label: 'Invoices', value: list.length, format: 'number' },
          { label: 'Paid', value: sum(list, x => x.paidTotal), format: 'money' },
          { label: 'Supplier due', value: sum(list, x => x.dueTotal), format: 'money' },
        ],
        series: byDay(list, x => x.date, x => x.grandTotal), seriesTitle: 'Purchases per day',
        columns: bySupplier
          ? [{ key: 'supplier', label: 'Supplier' }, { key: 'invoices', label: 'Invoices', align: 'right', format: 'number' }, { key: 'lines', label: 'Lines', align: 'right', format: 'number' }, { key: 'grandTotal', label: 'Total', align: 'right', format: 'money' }, { key: 'paidTotal', label: 'Paid', align: 'right', format: 'money' }, { key: 'dueTotal', label: 'Due', align: 'right', format: 'money' }]
          : [{ key: 'number', label: 'Purchase', format: 'mono' }, { key: 'date', label: 'Received', format: 'date' }, { key: 'supplier', label: 'Supplier' }, { key: 'invoiceNo', label: 'Supplier inv.' }, { key: 'lines', label: 'Lines', align: 'right', format: 'number' }, { key: 'grandTotal', label: 'Total', align: 'right', format: 'money' }, { key: 'paidTotal', label: 'Paid', align: 'right', format: 'money' }, { key: 'dueTotal', label: 'Due', align: 'right', format: 'money' }],
        rows, totals: { invoices: list.length, lines: sum(list, x => x.items), grandTotal: total, paidTotal: sum(list, x => x.paidTotal), dueTotal: sum(list, x => x.dueTotal) },
      }
    },

    async customers(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('customers', p)
      await mockDelay(400)
      const { customers, sales } = await seed()
      const rows = customers.filter(c => !c.isWalkIn)
        .filter(c => !p.filters.membership || (c.membership ?? 'none') === p.filters.membership)
        .filter(c => p.filters.due !== 'yes' || c.dueBalance > 0)
        .map((c) => {
          const s = sales.filter(x => x.customer === c.name && x.status === 'completed' && inRange(x.date, p.range))
          return { customerId: c.id, name: c.name, phone: c.phone, membership: c.membership ?? 'none', invoices: s.length, periodSpend: sum(s, x => x.grandTotal), lifetime: c.totalSpent, points: c.points, due: c.dueBalance, lastVisit: c.lastVisit }
        }).sort((a, b) => b.lifetime - a.lifetime)
      return {
        kpis: [
          { label: 'Customers', value: rows.length, format: 'number' },
          { label: 'Spend in period', value: sum(rows, r => r.periodSpend), format: 'money', hint: 'Named customers only' },
          { label: 'Outstanding due', value: sum(rows, r => r.due), format: 'money', hint: `${rows.filter(r => r.due).length} customers owe` },
          { label: 'Points liability', value: sum(rows, r => r.points), format: 'number', hint: 'Unredeemed points' },
        ],
        columns: [
          { key: 'name', label: 'Customer' }, { key: 'phone', label: 'Phone' },
          { key: 'membership', label: 'Membership', format: 'badge', tones: { Gold: 'warning', Silver: 'neutral', none: 'neutral' } },
          { key: 'invoices', label: 'Invoices', align: 'right', format: 'number' }, { key: 'periodSpend', label: 'Spend (period)', align: 'right', format: 'money' },
          { key: 'lifetime', label: 'Lifetime', align: 'right', format: 'money' }, { key: 'points', label: 'Points', align: 'right', format: 'number' },
          { key: 'due', label: 'Due', align: 'right', format: 'money' }, { key: 'lastVisit', label: 'Last visit', format: 'date' },
        ],
        rows, totals: { invoices: sum(rows, r => r.invoices), periodSpend: sum(rows, r => r.periodSpend), lifetime: sum(rows, r => r.lifetime), points: sum(rows, r => r.points), due: sum(rows, r => r.due) },
      }
    },

    async suppliers(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('suppliers', p)
      await mockDelay(400)
      const { purchases } = await seed()
      const { suppliers } = await extra()
      const names = [...new Set([...suppliers.map(s => s.name), ...purchases.map(x => x.supplier)])]
      const rows = names.map((name) => {
        const g = purchases.filter(x => x.supplier === name && x.status === 'received' && inRange(x.date, p.range))
        const purchased = sum(g, x => x.grandTotal)
        const returns = r2(purchased * ((h(name) % 4) / 100))
        const s = suppliers.find(x => x.name === name)
        return { supplierId: s?.id ?? null, supplier: name, phone: s?.phone ?? null, invoices: g.length, purchased, returns, paid: sum(g, x => x.paidTotal), periodDue: sum(g, x => x.dueTotal), balance: s?.dueBalance ?? sum(g, x => x.dueTotal) }
      }).filter(r => p.filters.due !== 'yes' || r.balance > 0).sort((a, b) => b.purchased - a.purchased)
      return {
        kpis: [
          { label: 'Purchased', value: sum(rows, r => r.purchased), format: 'money' },
          { label: 'Returned to suppliers', value: sum(rows, r => r.returns), format: 'money' },
          { label: 'Paid', value: sum(rows, r => r.paid), format: 'money' },
          { label: 'Total supplier balance', value: sum(rows, r => r.balance), format: 'money', hint: 'From the supplier ledger' },
        ],
        columns: [
          { key: 'supplier', label: 'Supplier' }, { key: 'phone', label: 'Phone' }, { key: 'invoices', label: 'Invoices', align: 'right', format: 'number' },
          { key: 'purchased', label: 'Purchased', align: 'right', format: 'money' }, { key: 'returns', label: 'Returns', align: 'right', format: 'money' },
          { key: 'paid', label: 'Paid', align: 'right', format: 'money' }, { key: 'periodDue', label: 'Due (period)', align: 'right', format: 'money' },
          { key: 'balance', label: 'Ledger balance', align: 'right', format: 'money' },
        ],
        rows, totals: { invoices: sum(rows, r => r.invoices), purchased: sum(rows, r => r.purchased), returns: sum(rows, r => r.returns), paid: sum(rows, r => r.paid), periodDue: sum(rows, r => r.periodDue), balance: sum(rows, r => r.balance) },
      }
    },

    async payments(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('payments', p)
      await mockDelay(400)
      const { sales, purchases } = await seed()
      const methods = ['cash', 'cash', 'cash', 'bkash', 'card', 'nagad']
      const accountOf = (m: string) => m === 'cash' ? 'Cash drawer' : m === 'card' ? 'Dutch-Bangla Bank ••4410' : m === 'bkash' ? 'bKash Merchant ••7788' : 'Nagad Merchant ••3321'
      const ins = sales.filter(s => s.paidTotal > 0 && inRange(s.date, p.range)).map((s) => {
        const m = methods[s.id % methods.length]!
        return { docType: 'sale', docId: s.id, ref: `PAY-${s.id}`, date: s.date, direction: 'in', party: s.customer, document: s.number, method: m, account: accountOf(m), amount: s.paidTotal }
      })
      const outs = purchases.filter(x => x.paidTotal > 0 && inRange(x.date, p.range)).map((x) => {
        const m = x.id % 3 === 0 ? 'cash' : 'bank'
        return { docType: 'purchase', docId: x.id, ref: `PAY-P${x.id}`, date: x.date, direction: 'out', party: x.supplier, document: x.number, method: m, account: m === 'cash' ? 'Cash drawer' : 'Dutch-Bangla Bank ••4410', amount: x.paidTotal }
      })
      const rows = [...ins, ...outs]
        .filter(r => (!p.filters.direction || r.direction === p.filters.direction) && (!p.filters.method || r.method === p.filters.method))
        .sort((a, b) => b.date.localeCompare(a.date))
      const tin = sum(rows.filter(r => r.direction === 'in'), r => r.amount)
      const tout = sum(rows.filter(r => r.direction === 'out'), r => r.amount)
      return {
        kpis: [
          { label: 'Received', value: tin, format: 'money' },
          { label: 'Paid out', value: tout, format: 'money' },
          { label: 'Net cash flow', value: r2(tin - tout), format: 'money' },
          { label: 'Digital share', value: tin ? (sum(rows.filter(r => r.direction === 'in' && r.method !== 'cash'), r => r.amount) / tin) * 100 : 0, format: 'percent', hint: 'bKash, Nagad and card' },
        ],
        series: byDay(rows.filter(r => r.direction === 'in'), r => r.date, r => r.amount), seriesTitle: 'Money received per day',
        columns: [
          { key: 'ref', label: 'Payment', format: 'mono' }, { key: 'date', label: 'Date', format: 'datetime' },
          { key: 'direction', label: 'Direction', format: 'badge', tones: { in: 'success', out: 'danger' } },
          { key: 'party', label: 'Customer / supplier' }, { key: 'document', label: 'Document', format: 'mono' },
          { key: 'method', label: 'Method', format: 'badge', tones: { cash: 'neutral', bkash: 'violet', nagad: 'warning', card: 'info', bank: 'brand' } },
          { key: 'account', label: 'Account' }, { key: 'amount', label: 'Amount', align: 'right', format: 'money' },
        ],
        rows, totals: { amount: r2(tin - tout) },
      }
    },

    async cashiers(p: ReportParams): Promise<ReportResult> {
      if (!api.useMocks) return real('cashiers', p)
      await mockDelay(400)
      const { sales } = await seed()
      const { sessions } = await extra()
      const names = [...new Set(sales.map(s => s.cashier))]
      const rows = names.map((c) => {
        const g = sales.filter(s => s.cashier === c && inRange(s.date, p.range))
        const done = g.filter(s => s.status === 'completed')
        const first = c.split(' ')[0]!
        const ss = sessions.filter(s => s.openedBy === first && s.status === 'closed')
        return {
          cashier: c, register: c.match(/\(([^)]+)\)/)?.[1] ?? 'Back office',
          invoices: done.length, sales: sum(done, s => s.grandTotal), avg: done.length ? r2(sum(done, s => s.grandTotal) / done.length) : 0,
          voids: g.filter(s => s.status === 'void').length, returns: h(c) % 5, sessions: ss.length, cashDiff: sum(ss, s => s.difference ?? 0),
        }
      }).filter(r => !p.filters.register || r.register === p.filters.register).sort((a, b) => b.sales - a.sales)
      return {
        kpis: [
          { label: 'Sales handled', value: sum(rows, r => r.sales), format: 'money' },
          { label: 'Invoices', value: sum(rows, r => r.invoices), format: 'number' },
          { label: 'Voids', value: sum(rows, r => r.voids), format: 'number', hint: 'Same-session cancellations' },
          { label: 'Net cash difference', value: sum(rows, r => r.cashDiff), format: 'money', hint: 'Over (+) / short (−) at close' },
        ],
        columns: [
          { key: 'cashier', label: 'Cashier' }, { key: 'register', label: 'Register' },
          { key: 'invoices', label: 'Invoices', align: 'right', format: 'number' }, { key: 'sales', label: 'Sales', align: 'right', format: 'money' },
          { key: 'avg', label: 'Avg basket', align: 'right', format: 'money' }, { key: 'voids', label: 'Voids', align: 'right', format: 'number' },
          { key: 'returns', label: 'Returns', align: 'right', format: 'number' }, { key: 'sessions', label: 'Sessions', align: 'right', format: 'number' },
          { key: 'cashDiff', label: 'Cash difference', align: 'right', format: 'money' },
        ],
        rows, totals: { invoices: sum(rows, r => r.invoices), sales: sum(rows, r => r.sales), voids: sum(rows, r => r.voids), returns: sum(rows, r => r.returns), sessions: sum(rows, r => r.sessions), cashDiff: sum(rows, r => r.cashDiff) },
      }
    },

    /** Filter option lists used by report pages. */
    async options(): Promise<{ manufacturers: string[]; forms: string[]; suppliers: string[] }> {
      if (!api.useMocks) return api.get('/reports/options')
      const { products, purchases } = await seed()
      return {
        manufacturers: [...new Set(products.map(x => x.manufacturer))].sort(),
        forms: [...new Set(products.map(x => x.dosageForm))].sort(),
        suppliers: [...new Set(purchases.map(x => x.supplier))].sort(),
      }
    },
  }
}
