import { FormAlert } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { formatCalendarDate } from '../candidate/candidateApi'
import { judgeWindowState } from './judgeApi'
import type { JudgeWindow } from './judgeApi'

/** When judging starts and ends, in the words of the current state. */
export function JudgeWindowNotice({ window }: { window: JudgeWindow | null }) {
  const { t, locale } = useLocale()
  const state = judgeWindowState(window)
  const dates =
    window?.opens_on && window.closes_on
      ? { from: formatCalendarDate(window.opens_on, locale), to: formatCalendarDate(window.closes_on, locale) }
      : null

  if (state === 'open' && !dates) return null

  const key = { open: 'judge.windowOpen', upcoming: 'judge.windowUpcoming', closed: 'judge.windowClosed', unknown: 'judge.windowUnknown' }[state]

  return (
    <FormAlert tone={state === 'open' ? 'success' : 'info'}>
      <p>{t(key, dates ?? {})}</p>
    </FormAlert>
  )
}
