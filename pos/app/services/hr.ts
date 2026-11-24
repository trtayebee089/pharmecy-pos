/**
 * HR, payroll, warehouses and own-account API modules (/api/pos/v1).
 * With useMocks on (development only) they resolve from app/mocks/hr.ts.
 */
import type { AccountProfile, AttendanceRow, AttendanceStatus, Department, Designation, Employee, Holiday, PayrollRun, Payslip, SalaryAdvance, Warehouse } from '~/types/hr'

const hrMocks = () => import('~/mocks/hr')

export function useEmployeesResource() {
  return useResource<Employee>('hr/employees', async () => (await hrMocks()).employees)
}
export function useDepartmentsResource() {
  return useResource<Department>('hr/departments', async () => (await hrMocks()).departments)
}
export function useDesignationsResource() {
  return useResource<Designation>('hr/designations', async () => (await hrMocks()).designations)
}
export function useHolidaysResource() {
  return useResource<Holiday>('hr/holidays', async () => (await hrMocks()).holidays)
}
export function useWarehousesResource() {
  return useResource<Warehouse>('admin/warehouses', async () => (await hrMocks()).warehouses)
}

export function useAttendanceApi() {
  const api = useApi()
  const saved = useState<Record<string, AttendanceRow[]>>('mock-attendance', () => ({}))
  return {
    async day(day: string): Promise<AttendanceRow[]> {
      if (!api.useMocks) return api.get('/hr/attendance', { date: day })
      await mockDelay(250)
      if (saved.value[day]) return structuredClone(saved.value[day]!)
      const m = await hrMocks()
      return m.employees.filter(e => e.isActive).map((e) => {
        const status = m.attendanceFor(e.id, day)
        return {
          employeeId: e.id, name: e.name, designation: e.designation, status,
          checkIn: status === 'present' ? '09:0' + (e.id % 6) : status === 'late' ? '09:4' + (e.id % 9) : '',
          checkOut: status === 'present' || status === 'late' ? '18:0' + (e.id % 5) : '',
        }
      })
    },
    /** Month grid for one employee: date → status. */
    async month(employeeId: number, year: number, month: number): Promise<Record<string, AttendanceStatus | null>> {
      if (!api.useMocks) return api.get('/hr/attendance/month', { employee_id: employeeId, year, month: month + 1 })
      await mockDelay(200)
      const m = await hrMocks()
      const days = new Date(year, month + 1, 0).getDate()
      const out: Record<string, AttendanceStatus | null> = {}
      for (let d = 1; d <= days; d++) {
        const key = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
        out[key] = m.attendanceFor(employeeId, key)
      }
      return out
    },
    async save(day: string, rows: AttendanceRow[], idempotencyKey: string): Promise<void> {
      if (!api.useMocks) return api.post('/hr/attendance', { date: day, rows }, idempotencyKey)
      await mockDelay(500)
      saved.value = { ...saved.value, [day]: structuredClone(toRaw(rows)) }
    },
  }
}

export function usePayrollApi() {
  const api = useApi()
  const runs = useState<PayrollRun[] | null>('mock-payroll-runs', () => null)
  async function mockRuns() {
    if (!runs.value) runs.value = structuredClone((await hrMocks()).payrollRuns)
    return runs.value!
  }
  return {
    async runs(): Promise<PayrollRun[]> {
      if (!api.useMocks) return api.get('/hr/payroll-runs')
      await mockDelay()
      return mockRuns()
    },
    async run(id: number): Promise<{ run: PayrollRun; payslips: Payslip[] } | null> {
      if (!api.useMocks) return api.get(`/hr/payroll-runs/${id}`)
      await mockDelay(300)
      const run = (await mockRuns()).find(r => r.id === id)
      return run ? { run, payslips: (await hrMocks()).payslips(id) } : null
    },
    async create(period: string, idempotencyKey: string): Promise<PayrollRun> {
      if (!api.useMocks) return api.post('/hr/payroll-runs', { period }, idempotencyKey)
      await mockDelay(700)
      const list = await mockRuns()
      const existing = list.find(r => r.period === period)
      if (existing) throw new ApiError(`A payroll run for ${period} already exists.`, 422, { period: ['Already exists'] })
      const id = Math.max(...list.map(r => r.id)) + 1
      const slips = (await hrMocks()).payslips(id)
      const gross = slips.reduce((s, p) => s + p.basic + p.additions, 0)
      const deductions = slips.reduce((s, p) => s + p.deductions + p.advanceRecovery, 0)
      const run: PayrollRun = { id, period, employees: slips.length, gross, deductions, net: gross - deductions, status: 'draft', paidAt: null, paidFrom: null }
      list.unshift(run)
      return run
    },
    async approve(id: number): Promise<void> {
      if (!api.useMocks) return api.post(`/hr/payroll-runs/${id}/approve`)
      await mockDelay(500)
      const r = (await mockRuns()).find(x => x.id === id)
      if (r) r.status = 'approved'
    },
    /** Paying posts one payment out per payslip; a paid run is immutable. */
    async pay(id: number, account: string, idempotencyKey: string): Promise<void> {
      if (!api.useMocks) return api.post(`/hr/payroll-runs/${id}/pay`, { account }, idempotencyKey)
      await mockDelay(800)
      const r = (await mockRuns()).find(x => x.id === id)
      if (r) Object.assign(r, { status: 'paid', paidAt: new Date().toISOString().slice(0, 10), paidFrom: account })
    },
    async accounts(): Promise<string[]> {
      if (!api.useMocks) return api.get('/finance/accounts/payout')
      return (await hrMocks()).accountsForPayout
    },
    async advances(): Promise<SalaryAdvance[]> {
      if (!api.useMocks) return api.get('/hr/salary-advances')
      await mockDelay()
      return (await hrMocks()).advances
    },
  }
}

export function useAccountApi() {
  const api = useApi()
  return {
    async profile(): Promise<AccountProfile> {
      if (!api.useMocks) return api.get('/me/profile')
      await mockDelay(250)
      const p = structuredClone((await hrMocks()).profile)
      const u = useAuthStore().user
      if (u) Object.assign(p, { name: u.name, username: u.username, role: u.role, warehouses: u.warehouses.map(w => w.name) })
      return p
    },
    async saveProfile(p: Pick<AccountProfile, 'name' | 'email' | 'phone'>): Promise<void> {
      if (!api.useMocks) return api.put('/me/profile', p)
      await mockDelay(500)
    },
    /** The API checks the current password and revokes other sessions on success. */
    async changePassword(currentPassword: string, newPassword: string): Promise<void> {
      if (!api.useMocks) return api.put('/me/password', { current_password: currentPassword, password: newPassword, password_confirmation: newPassword })
      await mockDelay(700)
      if (currentPassword === 'wrong') throw new ApiError('The current password is incorrect.', 422, { current: ['The current password is incorrect.'] })
    },
  }
}
