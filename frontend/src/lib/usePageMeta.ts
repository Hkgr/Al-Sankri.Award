import { useEffect } from 'react'
import { award } from '../content/site'

/**
 * Sets the tab title and the meta description of a public page. The
 * homepage passes no title and gets the award's full name.
 */
export function usePageMeta(title: string | null, description: string) {
  useEffect(() => {
    document.title = title ? `${title} | ${award.shortName}` : award.name
    document.querySelector('meta[name="description"]')?.setAttribute('content', description)
  }, [title, description])
}
