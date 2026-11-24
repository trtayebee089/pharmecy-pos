import type { Column } from '~/types/domain'

/** Client-side CSV export of the rows currently loaded (UTF-8 with BOM for Excel). */
export function downloadCsv(filename: string, columns: Column[], rows: Record<string, any>[]) {
  const esc = (v: unknown) => {
    const s = v === null || v === undefined ? '' : String(v)
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  const cols = columns.filter(c => c.label && !c.key.startsWith('__'))
  const lines = [cols.map(c => esc(c.label)).join(','), ...rows.map(r => cols.map(c => esc(r[c.key])).join(','))]
  const blob = new Blob([`﻿${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = filename
  a.click()
  URL.revokeObjectURL(a.href)
}
