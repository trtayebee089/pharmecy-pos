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
      if (saved.value[day]) return JSON.parse(JSON.stringify(saved.value[day]!)) as AttendanceRow[]
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
  const advanceStore = useState<SalaryAdvance[] | null>('mock-salary-advances', () => null)
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
    /** Only draft runs can be deleted; approved/paid runs are kept for audit. */
    async deleteRun(id: number): Promise<void> {
      if (!api.useMocks) return api.del(`/hr/payroll-runs/${id}`)
      await mockDelay(400)
      const list = await mockRuns()
      const r = list.find(x => x.id === id)
      if (r && r.status !== 'draft') throw new ApiError('Only draft payroll runs can be deleted.', 422, { status: ['Not a draft'] })
      runs.value = list.filter(x => x.id !== id)
    },
    /** Draft runs only: manual addition (bonus, overtime) with a note. Net is recomputed by the API. */
    async updatePayslip(runId: number, slipId: number, additions: number, note: string): Promise<void> {
      if (!api.useMocks) return api.put(`/hr/payroll-runs/${runId}/payslips/${slipId}`, { additions, note })
      await mockDelay(400)
    },
    async accounts(): Promise<string[]> {
      if (!api.useMocks) return api.get('/finance/accounts/payout')
      return (await hrMocks()).accountsForPayout
    },
    async advances(): Promise<SalaryAdvance[]> {
      if (!api.useMocks) return api.get('/hr/salary-advances')
      await mockDelay()
      advanceStore.value ??= structuredClone((await hrMocks()).advances)
      return advanceStore.value
    },
    /** Gives an advance (posts a payment out from the account); recovered from the next payroll runs. */
    async giveAdvance(payload: { employeeId: number; employee: string; amount: number; account: string }, idempotencyKey: string): Promise<SalaryAdvance> {
      if (!api.useMocks) return api.post('/hr/salary-advances', payload, idempotencyKey)
      await mockDelay(500)
      advanceStore.value ??= structuredClone((await hrMocks()).advances)
      const a: SalaryAdvance = { id: Math.max(0, ...advanceStore.value.map(x => x.id)) + 1, employee: payload.employee, amount: payload.amount, givenAt: new Date().toISOString().slice(0, 10), recovered: 0, status: 'open' }
      advanceStore.value = [a, ...advanceStore.value]
      return a
    },
    /** Cash repayment outside payroll. */
    async recordRecovery(id: number, amount: number, idempotencyKey: string): Promise<void> {
      if (!api.useMocks) return api.post(`/hr/salary-advances/${id}/recoveries`, { amount }, idempotencyKey)
      await mockDelay(450)
      const a = advanceStore.value?.find(x => x.id === id)
      if (a) { a.recovered = Math.min(a.amount, a.recovered + amount); if (a.recovered >= a.amount) a.status = 'recovered' }
    },
    /** Void an advance given by mistake (only before any recovery); the payment out is reversed. */
    async voidAdvance(id: number, reason: string): Promise<void> {
      if (!api.useMocks) return api.post(`/hr/salary-advances/${id}/void`, { reason })
      await mockDelay(400)
      const a = advanceStore.value?.find(x => x.id === id)
      if (a && a.recovered > 0) throw new ApiError('Advances with recoveries cannot be voided.', 422, { recovered: ['Already partly recovered'] })
      advanceStore.value = (advanceStore.value ?? []).filter(x => x.id !== id)
    },
  }
}

/**
 * Staff user administration writes (/api/pos/v1/admin/users). Granting Admin/Owner
 * and disabling users are permission-checked by the API (roles.manage / users.view).
 */
export function useUserAdminApi() {
  const api = useApi()
  return {
    async save(u: { id?: number; name: string; username: string; email: string; role: string; warehouses: string[] }, idempotencyKey: string): Promise<{ id: number }> {
      if (!api.useMocks) return u.id ? api.put(`/admin/users/${u.id}`, u) : api.post('/admin/users', u, idempotencyKey)
      await mockDelay(450)
      return { id: u.id ?? Date.now() }
    },
    async setActive(id: number, active: boolean): Promise<void> {
      if (!api.useMocks) return api.post(`/admin/users/${id}/${active ? 'enable' : 'disable'}`)
      await mockDelay(350)
    },
    /** Creates a role (e.g. a duplicate); returns its id. */
    async createRole(r: { name: string; description: string; permissions: string[]; maxDiscountPct: number }, idempotencyKey: string): Promise<{ id: number }> {
      if (!api.useMocks) return api.post('/admin/roles', r, idempotencyKey)
      await mockDelay(450)
      return { id: Date.now() }
    },
    async updateRoleDetails(id: number, r: { name: string; description: string }): Promise<void> {
      if (!api.useMocks) return api.put(`/admin/roles/${id}/details`, r)
      await mockDelay(350)
    },
    /** System roles and roles still assigned to users cannot be deleted (API returns 422). */
    async deleteRole(id: number): Promise<void> {
      if (!api.useMocks) return api.del(`/admin/roles/${id}`)
      await mockDelay(350)
    },
    /** Sends a reset link and forces a new password at next sign-in. No password is ever shown. */
    async resetPassword(id: number): Promise<void> {
      if (!api.useMocks) return api.post(`/admin/users/${id}/password-reset`)
      await mockDelay(400)
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
