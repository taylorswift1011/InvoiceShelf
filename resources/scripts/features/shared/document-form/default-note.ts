import { noteService } from '../../../api/services/note.service'
import type { ListParams } from '../../../types/api'
import type { NoteType } from '../../../types/domain/note'

/**
 * The text of the note marked default for a document type (Settings →
 * Customization → Notes), which a new invoice or estimate starts with, as it
 * did in 2.x. Null when there is none or notes cannot be read (a member
 * without the ability to view notes simply starts with an empty field).
 */
export async function fetchDefaultNote(type: NoteType): Promise<string | null> {
  try {
    const params: ListParams & { type: NoteType } = { type, limit: 100 }
    const response = await noteService.list(params)

    return response.data.find((note) => note.type === type && note.is_default)?.notes ?? null
  } catch {
    return null
  }
}
