/**
 * POS settings (incl. printers) and reward point settings
 * (/api/pos/v1/settings/pos, /settings/reward-points).
 * Secrets such as a PrintNode API key are write-only: the API never returns them.
 */
export interface PrinterProfile {
  id: number
  name: string
  role: 'receipt' | 'a4' | 'label'
  counter: string // cash register / counter it belongs to
  connection: 'browser' | 'qz' | 'printnode' | 'network'
  deviceName: string // OS printer name (QZ Tray), PrintNode printer id, or IP:port
  paper: '58mm' | '80mm' | 'A4' | '38x25mm' | '50x30mm'
  copies: number
  autoPrint: boolean
  openCashDrawer: boolean
  autoCut: boolean
  isDefault: boolean
}

export interface PosConfig {
  defaultCustomerId: number
  defaultWarehouseId: number
  defaultBillerId: number
  productsPerPage: number
  productGrid: 'comfortable' | 'compact'
  touchKeyboard: boolean
  sendSmsAfterSale: boolean
  requireCustomerForDue: boolean
  paymentMethods: { code: string; label: string; enabled: boolean; account: string; requiresReference: boolean }[]
  invoice: {
    format: 'thermal80' | 'thermal58' | 'a4'
    showLogo: boolean
    showGeneric: boolean
    showBatchExpiry: boolean
    showSavings: boolean
    showQr: boolean
    header: string
    footer: string
  }
  printers: PrinterProfile[]
  printNodeConfigured: boolean
}

export interface RewardPointConfig {
  active: boolean
  soldAmountPerPoint: number // ৳ spent to earn 1 point
  minimumSoldAmount: number // minimum invoice total to earn
  expiryDuration: number
  expiryType: 'Day' | 'Month' | 'Year'
  pointValue: number // ৳ value of 1 point when redeemed
  minRedeemPoints: number
  maxRedeemPercent: number // max % of an invoice payable with points
  excludeDiscountedItems: boolean
}

const posDefaults = (): PosConfig => ({
  defaultCustomerId: 1, defaultWarehouseId: 1, defaultBillerId: 1, productsPerPage: 24, productGrid: 'comfortable',
  touchKeyboard: false, sendSmsAfterSale: true, requireCustomerForDue: true,
  paymentMethods: [
    { code: 'cash', label: 'Cash', enabled: true, account: 'Cash in hand', requiresReference: false },
    { code: 'bkash', label: 'bKash', enabled: true, account: 'bKash merchant', requiresReference: true },
    { code: 'nagad', label: 'Nagad', enabled: true, account: 'Nagad merchant', requiresReference: true },
    { code: 'rocket', label: 'Rocket', enabled: false, account: 'Rocket merchant', requiresReference: true },
    { code: 'card', label: 'Card', enabled: true, account: 'City Bank POS', requiresReference: true },
    { code: 'cheque', label: 'Cheque', enabled: false, account: 'City Bank current', requiresReference: true },
    { code: 'customer_credit', label: 'Customer credit (due)', enabled: true, account: 'Receivables', requiresReference: false },
    { code: 'points', label: 'Reward points', enabled: true, account: 'Loyalty liability', requiresReference: false },
  ],
  invoice: {
    format: 'thermal80', showLogo: true, showGeneric: true, showBatchExpiry: true, showSavings: true, showQr: true,
    header: 'Red Pharma · Dhanmondi\nHouse 12, Road 7, Dhanmondi, Dhaka\n01711-000001',
    footer: 'Medicines once sold are returnable only as per pharmacy policy. Thank you!',
  },
  printers: [
    { id: 1, name: 'Counter 1 receipt', role: 'receipt', counter: 'Counter 1', connection: 'qz', deviceName: 'XP-80C', paper: '80mm', copies: 1, autoPrint: true, openCashDrawer: true, autoCut: true, isDefault: true },
    { id: 2, name: 'Counter 2 receipt', role: 'receipt', counter: 'Counter 2', connection: 'browser', deviceName: '', paper: '80mm', copies: 1, autoPrint: false, openCashDrawer: false, autoCut: true, isDefault: false },
    { id: 3, name: 'Office A4', role: 'a4', counter: 'Back office', connection: 'browser', deviceName: '', paper: 'A4', copies: 1, autoPrint: false, openCashDrawer: false, autoCut: false, isDefault: true },
    { id: 4, name: 'Shelf labels', role: 'label', counter: 'Store room', connection: 'qz', deviceName: 'Xprinter XP-365B', paper: '38x25mm', copies: 1, autoPrint: false, openCashDrawer: false, autoCut: false, isDefault: true },
  ],
  printNodeConfigured: false,
})
const rewardDefaults = (): RewardPointConfig => ({
  active: true, soldAmountPerPoint: 100, minimumSoldAmount: 200, expiryDuration: 12, expiryType: 'Month',
  pointValue: 1, minRedeemPoints: 50, maxRedeemPercent: 50, excludeDiscountedItems: false,
})

export function useSettingsApi() {
  const api = useApi()
  const posStore = useState<PosConfig | null>('mock-pos-config', () => null)
  const rewardStore = useState<RewardPointConfig | null>('mock-reward-config', () => null)
  return {
    async pos(): Promise<PosConfig> {
      if (api.useMocks) { await mockDelay(300); posStore.value ??= posDefaults(); return structuredClone(toRaw(posStore.value)) }
      return api.get('/settings/pos')
    },
    async savePos(c: PosConfig, printNodeKey?: string): Promise<void> {
      if (api.useMocks) {
        await mockDelay(500)
        posStore.value = { ...structuredClone(c), printNodeConfigured: c.printNodeConfigured || !!printNodeKey }
        return
      }
      return api.put('/settings/pos', { ...c, print_node_api_key: printNodeKey || undefined })
    },
    /** Sends a test page to one printer (server-side for PrintNode/network; QZ Tray/browser print locally). */
    async testPrint(printerId: number): Promise<{ ok: boolean; message: string }> {
      if (api.useMocks) { await mockDelay(900); return { ok: true, message: 'Test page sent' } }
      return api.post(`/settings/printers/${printerId}/test`)
    },
    async rewards(): Promise<RewardPointConfig> {
      if (api.useMocks) { await mockDelay(300); rewardStore.value ??= rewardDefaults(); return structuredClone(toRaw(rewardStore.value)) }
      return api.get('/settings/reward-points')
    },
    async saveRewards(c: RewardPointConfig): Promise<void> {
      if (api.useMocks) { await mockDelay(500); rewardStore.value = structuredClone(c); return }
      return api.put('/settings/reward-points', c)
    },
  }
}
