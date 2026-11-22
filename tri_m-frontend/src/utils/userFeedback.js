import { ref, useId } from 'vue'

export class PosError extends Error {
  constructor(message, { fields = {}, status, cause } = {}) {
    super(message, { cause })
    this.fields = fields
    this.status = status
  }
}

export const expiredSessionMessage = 'Your session has expired. Log in again to continue.'

export function userMessage(problem, fallback = 'Something went wrong. Please try again.') {
  return problem instanceof PosError ? problem.message : fallback
}

export function useFormFeedback(visibleFields) {
  const id = useId()
  const alert = ref('')
  const fields = ref({})
  const inline = (field) => fields.value[field]?.[0] ?? ''
  const fieldId = (field) => id + '-error-' + field

  function clear() {
    alert.value = ''
    fields.value = {}
  }

  function show(problem, fallback) {
    fields.value = problem instanceof PosError ? problem.fields : {}
    const details = Object.entries(fields.value)
    const unmapped = details.find(([key]) => !visibleFields.includes(key))
    alert.value = unmapped?.[1]?.[0] ?? (details.length ? '' : userMessage(problem, fallback))
  }

  function bind(field) {
    return inline(field) ? { 'aria-invalid': 'true', 'aria-describedby': fieldId(field) } : {}
  }

  return { alert, clear, show, bind, inline, fieldId }
}
