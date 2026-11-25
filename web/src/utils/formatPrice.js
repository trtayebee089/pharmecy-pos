/** Taka with paisa when there are any (৳1.20 per piece must not show as ৳1). */
export function formatPrice(value) {
  const amount = Number(value || 0)
  const whole = Math.abs(amount - Math.round(amount)) < 0.005
  return new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    minimumFractionDigits: whole ? 0 : 2,
    maximumFractionDigits: whole ? 0 : 2,
  }).format(amount)
}
