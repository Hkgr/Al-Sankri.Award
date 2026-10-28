import { createContext, useContext, useEffect } from 'react'

export type Location = {
  pathname: string
  search: string
  hash: string
}

/**
 * keepPosition: the same page with new query values (a table's filters):
 * the scroll position and focus stay where they are.
 */
export type NavigateOptions = { replace?: boolean; keepPosition?: boolean }

export type RouterValue = {
  location: Location
  navigate: (to: string, options?: NavigateOptions) => void
  /**
   * Registers a question asked before leaving the current page (null removes
   * it). Returns a function that removes this registration.
   */
  setLeaveWarning: (message: string | null) => () => void
  /** Asks the registered question, if any; true when leaving may proceed. */
  confirmLeave: () => boolean
}

export const RouterContext = createContext<RouterValue | null>(null)

export function useRouter(): RouterValue {
  const router = useContext(RouterContext)
  if (!router) throw new Error('useRouter must be used inside RouterProvider')
  return router
}

export function useSearchParam(name: string): string | null {
  const { location } = useRouter()
  return new URLSearchParams(location.search).get(name)
}

/**
 * While `message` is set, leaving the page (a link, back/forward, a reload
 * or closing the tab) first asks for confirmation.
 */
export function useLeaveWarning(message: string | null) {
  const { setLeaveWarning } = useRouter()

  useEffect(() => {
    if (!message) return
    const remove = setLeaveWarning(message)
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => {
      remove()
      window.removeEventListener('beforeunload', onBeforeUnload)
    }
  }, [message, setLeaveWarning])
}
