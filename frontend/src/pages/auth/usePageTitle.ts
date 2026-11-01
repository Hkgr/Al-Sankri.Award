import { useEffect } from 'react'
import { useSite } from '../../content/useSite'

/** Sets the browser tab title for an account page. */
export function usePageTitle(title: string) {
  const { award } = useSite()

  useEffect(() => {
    document.title = `${title} | ${award.shortName}`
  }, [title, award])
}
