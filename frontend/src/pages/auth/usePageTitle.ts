import { useEffect } from 'react'

const SITE_NAME = 'جائزة الدكتور عبد القادر سنكري'

/** Sets the browser tab title for an account page. */
export function usePageTitle(title: string) {
  useEffect(() => {
    document.title = `${title} | ${SITE_NAME}`
  }, [title])
}
