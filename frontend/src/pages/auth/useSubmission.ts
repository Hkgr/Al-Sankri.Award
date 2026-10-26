import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError, errorMessage } from '../../lib/api'
import type { FieldErrors } from '../../lib/api'

export const ALERT_ID = 'form-alert'

/**
 * Submission state shared by the account forms: a busy flag that blocks
 * double submits, field errors from Laravel's validation, and one summary
 * message that receives focus so it is read out.
 */
export function useSubmission() {
  const [busy, setBusy] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [alert, setAlert] = useState<string | null>(null)
  const [failures, setFailures] = useState(0)
  const busyRef = useRef(false)

  // After each failed attempt, once the message is on screen, move focus to it.
  useEffect(() => {
    if (failures > 0) document.getElementById(ALERT_ID)?.focus()
  }, [failures])

  const submit = useCallback(async <T,>(action: () => Promise<T>): Promise<T | undefined> => {
    if (busyRef.current) return undefined
    busyRef.current = true
    setBusy(true)
    setAlert(null)
    setFieldErrors({})
    try {
      return await action()
    } catch (error) {
      if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
        const messages = Object.values(error.errors).flat()
        setFieldErrors(error.errors)
        setAlert(messages.length === 1 ? messages[0] : 'تحقّق من الحقول المشار إليها أدناه.')
      } else {
        setAlert(errorMessage(error))
      }
      setFailures((count) => count + 1)
      return undefined
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }, [])

  const fieldError = (name: string) => fieldErrors[name]?.[0]

  return { busy, alert, setAlert, fieldError, submit }
}
