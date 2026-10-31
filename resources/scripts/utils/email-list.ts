import { email } from '@vuelidate/validators'

/**
 * Email address lists as people type them in CC and BCC fields: one or many,
 * separated by commas, semicolons or line breaks. Mirrors the server's
 * `App\Support\Mail\RecipientList`, so the dialogs split and join the same way
 * the send endpoints do.
 */

const SEPARATORS = /[,;\r\n]+/

/** Split, trim and de-duplicate (case-insensitively, first spelling wins). */
export function parseEmailList(value: string | string[] | null | undefined): string[] {
  if (value === null || value === undefined) {
    return []
  }

  const parts = Array.isArray(value) ? value : value.split(SEPARATORS)
  const seen = new Set<string>()
  const addresses: string[] = []

  for (const part of parts) {
    const address = String(part ?? '').trim()
    const key = address.toLowerCase()

    if (address === '' || seen.has(key)) {
      continue
    }

    seen.add(key)
    addresses.push(address)
  }

  return addresses
}

/** The list as the dialogs show it: addresses joined with ", ". */
export function formatEmailList(value: string | string[] | null | undefined): string {
  return parseEmailList(value).join(', ')
}

/**
 * Vuelidate predicate: empty, or every address in the list is valid. Reuses
 * vuelidate's own single-address check so the rule matches the `To` field.
 */
export function isEmailList(value: unknown): boolean {
  if (value === null || value === undefined || value === '') {
    return true
  }

  if (typeof value !== 'string' && !Array.isArray(value)) {
    return false
  }

  return parseEmailList(value as string | string[]).every((address) =>
    Boolean(email.$validator(address, undefined, undefined)),
  )
}
