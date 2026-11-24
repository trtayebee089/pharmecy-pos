/**
 * DEVELOPMENT-ONLY MOCK DATA: HR, payroll, warehouses and the signed-in account.
 * Loaded only when runtimeConfig.public.useMocks is true.
 */
import type {
  AccountProfile, AttendanceStatus, Department, Designation, Employee, Holiday, PayrollRun, Payslip, SalaryAdvance, Warehouse,
} from '~/types/hr'

const iso = (d: Date) => d.toISOString().slice(0, 10)

export const departments: Department[] = [
  { id: 1, name: 'Counter & dispensing', employees: 5 },
  { id: 2, name: 'Store & receiving', employees: 2 },
  { id: 3, name: 'Delivery', employees: 2 },
  { id: 4, name: 'Administration', employees: 2 },
]

export const designations: Designation[] = [
  { id: 1, name: 'Senior pharmacist', department: 'Counter & dispensing' },
  { id: 2, name: 'Pharmacist', department: 'Counter & dispensing' },
  { id: 3, name: 'Cashier', department: 'Counter & dispensing' },
  { id: 4, name: 'Store keeper', department: 'Store & receiving' },
  { id: 5, name: 'Delivery rider', department: 'Delivery' },
  { id: 6, name: 'Accounts officer', department: 'Administration' },
  { id: 7, name: 'Branch manager', department: 'Administration' },
]

const people: [string, string, string, number, string | null, string][] = [
  ['Sabbir Hossain', 'Counter & dispensing', 'Senior pharmacist', 32000, 'sabbir', 'Dhanmondi'],
  ['Moushumi Akter', 'Counter & dispensing', 'Cashier', 18000, 'moushumi', 'Dhanmondi'],
  ['Tania Islam', 'Counter & dispensing', 'Cashier', 18000, 'tania', 'Uttara'],
  ['Nusrat Jahan', 'Counter & dispensing', 'Pharmacist', 26000, null, 'Uttara'],
  ['Imran Chowdhury', 'Counter & dispensing', 'Pharmacist', 25000, null, 'Dhanmondi'],
  ['Rakib Uddin', 'Store & receiving', 'Store keeper', 20000, 'rakib', 'Dhanmondi'],
  ['Habib Sarker', 'Store & receiving', 'Store keeper', 19000, null, 'Uttara'],
  ['Shuvo Rahman', 'Delivery', 'Delivery rider', 15000, null, 'Dhanmondi'],
  ['Karim Khan', 'Delivery', 'Delivery rider', 15000, null, 'Uttara'],
  ['Farhana Begum', 'Administration', 'Accounts officer', 28000, null, 'Dhanmondi'],
  ['Arif Ahmed', 'Administration', 'Branch manager', 45000, null, 'Dhanmondi'],
]

export const employees: Employee[] = people.map(([name, department, designation, basicSalary, user, warehouse], i) => ({
  id: i + 1,
  code: `EMP-${String(i + 1).padStart(3, '0')}`,
  name, department, designation, basicSalary, warehouse,
  phone: `0171${String(2000000 + i * 13579).slice(0, 7)}`,
  email: `${name.split(' ')[0]!.toLowerCase()}@redpharma.test`,
  joinDate: iso(new Date(2023 + (i % 3), (i * 5) % 12, 1 + (i * 7) % 27)),
  userAccount: user,
  isActive: true,
}))

/** Deterministic attendance status for an employee on a date. */
export function attendanceFor(employeeId: number, day: string): AttendanceStatus | null {
  const d = new Date(day)
  if (d.getDay() === 5) return null // Friday weekly off
  if (d > new Date()) return null
  const n = (employeeId * 31 + d.getDate() * 17 + d.getMonth() * 7) % 23
  return n === 0 ? 'absent' : n === 1 ? 'leave' : n < 5 ? 'late' : 'present'
}

const year = new Date().getFullYear()
export const holidays: Holiday[] = [
  [`${year}-02-21`, 'International Mother Language Day'],
  [`${year}-03-26`, 'Independence Day'],
  [`${year}-04-14`, 'Pohela Boishakh'],
  [`${year}-05-01`, 'May Day'],
  [`${year}-03-31`, 'Eid-ul-Fitr'],
  [`${year}-04-01`, 'Eid-ul-Fitr (2nd day)'],
  [`${year}-06-07`, 'Eid-ul-Adha'],
  [`${year}-06-08`, 'Eid-ul-Adha (2nd day)'],
  [`${year}-08-15`, 'National Mourning Day'],
  [`${year}-10-02`, 'Durga Puja (Bijoya Dashami)'],
  [`${year}-12-16`, 'Victory Day'],
  [`${year}-12-25`, 'Christmas Day'],
].map(([date, name], i) => ({ id: i + 1, date: date!, name: name!, warehouse: 'All warehouses' }))
  .sort((a, b) => a.date.localeCompare(b.date))

const now = new Date()
function period(offset: number) {
  const d = new Date(now.getFullYear(), now.getMonth() - offset, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function payslips(runId: number): Payslip[] {
  return employees.map((e, i) => {
    const additions = (i + runId) % 3 === 0 ? 2000 : (i + runId) % 4 === 0 ? 1000 : 0
    const absent = (e.id * 3 + runId) % 4
    const deductions = Math.round((e.basicSalary / 26) * absent)
    const advanceRecovery = e.id === 2 || e.id === 8 ? 2000 : 0
    return {
      id: runId * 100 + e.id, employeeId: e.id, name: e.name, designation: e.designation,
      basic: e.basicSalary, additions, deductions, advanceRecovery,
      net: e.basicSalary + additions - deductions - advanceRecovery,
      presentDays: 26 - absent, workingDays: 26,
    }
  })
}

export const payrollRuns: PayrollRun[] = [0, 1, 2, 3, 4, 5].map((offset) => {
  const id = 40 - offset
  const slips = payslips(id)
  const gross = slips.reduce((s, p) => s + p.basic + p.additions, 0)
  const deductions = slips.reduce((s, p) => s + p.deductions + p.advanceRecovery, 0)
  const status = offset === 0 ? 'draft' : offset === 1 ? 'approved' : 'paid'
  return {
    id, period: period(offset), employees: slips.length, gross, deductions, net: gross - deductions,
    status, paidAt: status === 'paid' ? iso(new Date(now.getFullYear(), now.getMonth() - offset + 1, 5)) : null,
    paidFrom: status === 'paid' ? 'Dutch-Bangla Bank ••4410' : null,
  }
})

export const advances: SalaryAdvance[] = [
  { id: 1, employee: 'Moushumi Akter', amount: 8000, givenAt: iso(new Date(now.getFullYear(), now.getMonth() - 3, 10)), recovered: 6000, status: 'open' },
  { id: 2, employee: 'Shuvo Rahman', amount: 6000, givenAt: iso(new Date(now.getFullYear(), now.getMonth() - 2, 18)), recovered: 4000, status: 'open' },
  { id: 3, employee: 'Rakib Uddin', amount: 5000, givenAt: iso(new Date(now.getFullYear(), now.getMonth() - 7, 4)), recovered: 5000, status: 'recovered' },
  { id: 4, employee: 'Imran Chowdhury', amount: 10000, givenAt: iso(new Date(now.getFullYear(), now.getMonth() - 9, 22)), recovered: 10000, status: 'recovered' },
]

export const accountsForPayout = ['Cash (Dhanmondi drawer)', 'Dutch-Bangla Bank ••4410', 'bKash Merchant ••7788']

export const warehouses: Warehouse[] = [
  { id: 1, code: 'DHK1', name: 'Red Pharma · Dhanmondi', type: 'store', address: 'House 12, Road 7, Dhanmondi, Dhaka 1205', phone: '01711000100', invoiceHeader: 'Red Pharma · Dhanmondi\nDrug licence DC-12345', invoiceFooter: 'Medicines once sold are returnable only as per pharmacy policy.', isActive: true },
  { id: 2, code: 'UTR1', name: 'Red Pharma · Uttara', type: 'store', address: 'Sector 7, Road 15, Uttara, Dhaka 1230', phone: '01711000200', invoiceHeader: 'Red Pharma · Uttara\nDrug licence DC-23456', invoiceFooter: 'Thank you for choosing Red Pharma.', isActive: true },
  { id: 3, code: 'CW01', name: 'Central warehouse · Tejgaon', type: 'warehouse', address: 'Tejgaon I/A, Dhaka 1208', phone: '01711000300', invoiceHeader: '', invoiceFooter: '', isActive: true },
]

export const profile: AccountProfile = {
  name: 'Fahim Rahman', username: 'demo.admin', email: 'owner@example.test', phone: '01711000999', role: 'Admin',
  warehouses: ['Red Pharma · Dhanmondi', 'Red Pharma · Uttara'],
  signIns: [
    { at: new Date(Date.now() - 6e5).toISOString(), device: 'Chrome · Windows', ip: '103.88.x.x', ok: true },
    { at: new Date(Date.now() - 9e7).toISOString(), device: 'Chrome · Windows', ip: '103.88.x.x', ok: true },
    { at: new Date(Date.now() - 1.8e8).toISOString(), device: 'Safari · iPhone', ip: '37.111.x.x', ok: false },
    { at: new Date(Date.now() - 2.6e8).toISOString(), device: 'Chrome · Windows', ip: '103.88.x.x', ok: true },
  ],
}
