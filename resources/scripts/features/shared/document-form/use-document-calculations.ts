import { computed, type Ref } from 'vue'
import type { Tax } from '../../../types/domain/tax'

export interface DocumentItem {
  id: number | string
  name: string
  description: string | null
  quantity: number
  price: number
  discount: number
  discount_val: number
  discount_type: 'fixed' | 'percentage'
  tax: number
  total: number
  sub_total?: number
  totalTax?: number
  totalSimpleTax?: number
  totalCompoundTax?: number
  taxes?: Partial<Tax>[]
  item_id?: number | null
  /** From a zero-rated catalogue item: left out of document-level taxes. */
  zero_rated?: boolean
  unit_name?: string | null
  invoice_id?: number | null
  estimate_id?: number | null
  [key: string]: unknown
}

export interface DocumentFormData {
  id: number | null
  customer: Record<string, unknown> | null
  customer_id: number | null
  items: DocumentItem[]
  taxes: DocumentTax[]
  discount: number
  discount_val: number
  discount_type: 'fixed' | 'percentage'
  tax_per_item: string | null
  tax_included: boolean | null
  discount_per_item: string | null
  notes: string | null
  template_name: string | null
  exchange_rate?: number | null
  currency_id?: number
  selectedCurrency?: Record<string, unknown> | string
  [key: string]: unknown
}

export interface DocumentTax {
  id: number | string
  tax_type_id: number
  name: string
  amount: number
  percent: number | null
  calculation_type: string | null
  fixed_amount: number
  compound_tax: boolean
  type?: string
  [key: string]: unknown
}

export interface DocumentStore {
  getSubTotal: number
  getNetTotal: number
  getTotalSimpleTax: number
  getTotalCompoundTax: number
  getTotalTax: number
  getSubtotalWithDiscount: number
  getTotal: number
  [key: string]: unknown
}

export interface UseDocumentCalculationsOptions {
  items: Ref<DocumentItem[]>
  taxes: Ref<DocumentTax[]>
  discountVal: Ref<number>
  taxPerItem: Ref<string | null>
  taxIncluded: Ref<boolean | null>
}

export function useDocumentCalculations(options: UseDocumentCalculationsOptions) {
  const { items, taxes, discountVal, taxPerItem, taxIncluded } = options

  const subTotal = computed<number>(() => {
    return items.value.reduce((sum: number, item: DocumentItem) => {
      return sum + (item.total ?? 0)
    }, 0)
  })

  const totalSimpleTax = computed<number>(() => {
    if (taxPerItem.value === 'YES') {
      return items.value.reduce((sum: number, item: DocumentItem) => {
        return sum + (item.taxes ?? []).reduce((itemSum, tax) => {
          return tax.compound_tax ? itemSum : itemSum + (tax.amount ?? 0)
        }, 0)
      }, 0)
    }

    return taxes.value.reduce((sum: number, tax: DocumentTax) => {
      if (!tax.compound_tax) {
        return sum + (tax.amount ?? 0)
      }
      return sum
    }, 0)
  })

  const totalCompoundTax = computed<number>(() => {
    if (taxPerItem.value === 'YES') {
      return items.value.reduce((sum: number, item: DocumentItem) => {
        return sum + (item.taxes ?? []).reduce((itemSum, tax) => {
          return tax.compound_tax ? itemSum + (tax.amount ?? 0) : itemSum
        }, 0)
      }, 0)
    }

    return taxes.value.reduce((sum: number, tax: DocumentTax) => {
      if (tax.compound_tax) {
        return sum + (tax.amount ?? 0)
      }
      return sum
    }, 0)
  })

  const totalTax = computed<number>(() => {
    return totalSimpleTax.value + totalCompoundTax.value
  })

  const subtotalWithDiscount = computed<number>(() => {
    return subTotal.value - discountVal.value
  })

  const netTotal = computed<number>(() => {
    if (taxIncluded.value) {
      return subtotalWithDiscount.value - totalSimpleTax.value
    }

    return subtotalWithDiscount.value
  })

  const total = computed<number>(() => {
    if (taxIncluded.value) {
      return subtotalWithDiscount.value + totalCompoundTax.value
    }
    return subtotalWithDiscount.value + totalTax.value
  })

  return {
    subTotal,
    totalSimpleTax,
    totalCompoundTax,
    totalTax,
    subtotalWithDiscount,
    netTotal,
    total,
  }
}

/**
 * What document-level taxes are charged on: the lines that are not
 * zero-rated, less their proportional share of the document discount. With
 * no zero-rated line this is exactly the discounted subtotal. Mirrors
 * `App\Support\DocumentTaxes::taxableBase()` on the server.
 */
export function documentTaxBase(
  items: Pick<DocumentItem, 'total' | 'zero_rated'>[],
  subTotal: number,
  discountVal: number,
): number {
  const taxable = items.reduce((sum, item) => sum + (item.zero_rated ? 0 : (item.total ?? 0)), 0)

  if (taxable === subTotal) {
    return subTotal - discountVal
  }

  if (subTotal === 0) {
    return 0
  }

  return taxable - Math.round((discountVal * taxable) / subTotal)
}

/** Calculate item-level subtotal (price * quantity) */
export function calcItemSubtotal(price: number, quantity: number): number {
  return Math.round(price * quantity)
}

/** Calculate item-level discount value */
export function calcItemDiscountVal(
  subtotal: number,
  discount: number,
  discountType: 'fixed' | 'percentage',
): number {
  const absSubtotal = Math.abs(subtotal)
  if (discountType === 'percentage') {
    return Math.round((absSubtotal * discount) / 100)
  }
  return Math.min(Math.round(discount * 100), absSubtotal)
}

/** Calculate item-level total after discount */
export function calcItemTotal(subtotal: number, discountVal: number): number {
  return subtotal - discountVal
}

/**
 * Calculate tax amount for a given total and tax config.
 *
 * A compound tax is charged on top of an inclusive amount, or on the base plus
 * every simple (non-compound) tax when the amount is tax-exclusive.
 *
 * @param total Base amount in cents (document subtotal after discount, or an item total after discount)
 * @param percent Percentage rate, when the tax is percentage based
 * @param fixedAmount Flat amount in cents, when the tax is fixed
 * @param calculationType `'fixed'` or `'percentage'`
 * @param taxIncluded Whether the base already includes the tax (back it out)
 * @param compoundTax Whether the tax is charged on top of the simple taxes
 * @param simpleTaxTotal Sum of the non-compound tax amounts in cents
 */
export function calcTaxAmount(
  total: number,
  percent: number | null,
  fixedAmount: number | null,
  calculationType: string | null,
  taxIncluded: boolean | null,
  compoundTax = false,
  simpleTaxTotal = 0,
): number {
  if (calculationType === 'fixed' && fixedAmount != null) {
    return fixedAmount
  }
  if (!total || !percent) return 0
  if (compoundTax) {
    if (taxIncluded) {
      return Math.round((total * percent) / 100)
    }

    return Math.round(((total + simpleTaxTotal) * percent) / 100)
  }
  if (taxIncluded) {
    return Math.round(total - total / (1 + percent / 100))
  }
  return Math.round((total * percent) / 100)
}
