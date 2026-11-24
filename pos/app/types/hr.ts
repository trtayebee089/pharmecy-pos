// HR, payroll, warehouses and account types (mirror /api/pos/v1).

export interface Department { id: number; name: string; employees: number }
export interface Designation { id: number; name: string; department: string }

export interface Employee {
  id: number
  code: string
  name: string
  phone: string
  email: string
  department: string
  designation: string
  joinDate: string
  basicSalary: number
  userAccount: string | null
  warehouse: string
  isActive: boolean
}

export type AttendanceStatus = 'present' | 'absent' | 'leave' | 'late'

export interface AttendanceRow {
  employeeId: number
  name: string
  designation: string
  status: AttendanceStatus | null
  checkIn: string
  checkOut: string
}

export interface Holiday { id: number; date: string; name: string; warehouse: string }

export type PayrollStatus = 'draft' | 'approved' | 'paid'

export interface PayrollRun {
  id: number
  period: string // YYYY-MM
  employees: number
  gross: number
  deductions: number
  net: number
  status: PayrollStatus
  paidAt: string | null
  paidFrom: string | null
}

export interface Payslip {
  id: number
  employeeId: number
  name: string
  designation: string
  basic: number
  additions: number
  deductions: number
  advanceRecovery: number
  net: number
  presentDays: number
  workingDays: number
}

export interface SalaryAdvance {
  id: number
  employee: string
  amount: number
  givenAt: string
  recovered: number
  status: 'open' | 'recovered'
}

export interface Warehouse {
  id: number
  code: string
  name: string
  type: 'store' | 'warehouse'
  address: string
  phone: string
  invoiceHeader: string
  invoiceFooter: string
  isActive: boolean
}

export interface AccountProfile {
  name: string
  username: string
  email: string
  phone: string
  role: string
  warehouses: string[]
  signIns: { at: string; device: string; ip: string; ok: boolean }[]
}
