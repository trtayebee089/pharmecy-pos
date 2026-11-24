/** DEVELOPMENT-ONLY MOCK DATA for catalog master data. Loaded only when useMocks is true. */
import { products } from './seed'

export interface Generic { id: number; name: string; therapeuticClass: string; products: number; isActive: boolean }

const classes: Record<string, string> = {
  Paracetamol: 'Analgesic / antipyretic', Omeprazole: 'Proton pump inhibitor', Esomeprazole: 'Proton pump inhibitor', Pantoprazole: 'Proton pump inhibitor',
  Fexofenadine: 'Antihistamine', Cetirizine: 'Antihistamine', Desloratadine: 'Antihistamine', Montelukast: 'Leukotriene antagonist',
  Azithromycin: 'Macrolide antibiotic', Ciprofloxacin: 'Quinolone antibiotic', Metronidazole: 'Antiprotozoal', Cefixime: 'Cephalosporin antibiotic',
  Amlodipine: 'Calcium channel blocker', Metformin: 'Antidiabetic', Rosuvastatin: 'Statin',
}

export function generics(): Generic[] {
  const names = [...new Set(products.map(p => p.generic).filter(Boolean) as string[])].sort()
  return names.map((name, i) => ({
    id: i + 1, name, therapeuticClass: classes[name] ?? 'Other',
    products: products.filter(p => p.generic === name).length, isActive: true,
  }))
}
