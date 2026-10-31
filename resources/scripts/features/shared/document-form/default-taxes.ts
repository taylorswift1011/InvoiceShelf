import { taxTypeService } from '../../../api/services/tax-type.service'
import { generateClientId } from '../../../utils'
import type { DocumentTax } from './use-document-calculations'

/**
 * The document-level tax rows a new invoice or estimate starts with: every
 * sales tax marked "Add to new documents" (Settings → Tax Types). Amounts start
 * at zero; the totals recompute them as lines are added, leaving zero-rated
 * lines out. Empty when there are none or tax types cannot be read.
 */
export async function fetchDefaultDocumentTaxes(): Promise<DocumentTax[]> {
  try {
    const response = await taxTypeService.list({ limit: 'all', transaction_type: 'sales' })

    return response.data
      .filter((taxType) => taxType.is_default)
      .map((taxType) => ({
        id: generateClientId(),
        tax_type_id: taxType.id,
        name: taxType.name,
        percent: taxType.percent,
        calculation_type: taxType.calculation_type,
        fixed_amount: taxType.fixed_amount ?? 0,
        compound_tax: taxType.compound_tax ?? false,
        amount: taxType.calculation_type === 'fixed' ? (taxType.fixed_amount ?? 0) : 0,
      }))
  } catch {
    return []
  }
}
