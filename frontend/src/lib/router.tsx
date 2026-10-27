import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { AnchorHTMLAttributes, MouseEvent, ReactNode } from 'react'
import { RouterContext, useRouter } from './useRouter'
import type { Location, NavigateOptions } from './useRouter'

/**
 * A deliberately small history router for the handful of public pages.
 * Laravel serves the same index.html for each of these paths, so a direct
 * visit or refresh lands on the right page (backend/routes/web.php).
 */

function currentLocation(): Location {
  const { pathname, search, hash } = window.location
  return { pathname, search, hash }
}

export function RouterProvider({ children }: { children: ReactNode }) {
  const [location, setLocation] = useState(currentLocation)
  const firstRender = useRef(true)
  // The question to ask before leaving a page with unsaved input (useLeaveWarning).
  const leaveWarning = useRef<string | null>(null)
  const shownLocation = useRef(location)

  const confirmLeave = useCallback(() => {
    const message = leaveWarning.current
    if (!message) return true
    if (!window.confirm(message)) return false
    leaveWarning.current = null
    return true
  }, [])

  const setLeaveWarning = useCallback((message: string | null) => {
    leaveWarning.current = message
    return () => {
      if (leaveWarning.current === message) leaveWarning.current = null
    }
  }, [])

  useEffect(() => {
    shownLocation.current = location
  }, [location])

  useEffect(() => {
    const onPopState = () => {
      if (!confirmLeave()) {
        // Stay: put the page that is still shown back into the address bar.
        const { pathname, search, hash } = shownLocation.current
        window.history.pushState(null, '', `${pathname}${search}${hash}`)
        return
      }
      setLocation(currentLocation())
    }
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [confirmLeave])

  const navigate = useCallback(
    (to: string, options: NavigateOptions = {}) => {
      const url = new URL(to, window.location.href)
      const target = `${url.pathname}${url.search}${url.hash}`
      const current = shownLocation.current
      if (target !== `${current.pathname}${current.search}${current.hash}` && !confirmLeave()) return
      if (url.origin !== window.location.origin) {
        window.location.assign(url.href)
        return
      }
      if (options.replace) {
        window.history.replaceState(null, '', target)
      } else {
        window.history.pushState(null, '', target)
      }
      setLocation({ pathname: url.pathname, search: url.search, hash: url.hash })
    },
    [confirmLeave],
  )

  // After each client-side navigation: scroll to the requested section, or to
  // the top with focus on <main> so assistive technology announces the page.
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    const target = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null
    if (target) {
      target.scrollIntoView()
      return
    }
    window.scrollTo(0, 0)
    document.getElementById('main')?.focus({ preventScroll: true })
  }, [location.pathname, location.search, location.hash])

  const value = useMemo(
    () => ({ location, navigate, setLeaveWarning, confirmLeave }),
    [location, navigate, setLeaveWarning, confirmLeave],
  )

  return <RouterContext.Provider value={value}>{children}</RouterContext.Provider>
}

type LinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }

/**
 * An ordinary link that navigates without a full reload when it stays on this
 * site. Modified clicks (new tab, download) keep the browser's behaviour.
 */
export function Link({ href, onClick, children, ...rest }: LinkProps) {
  const { location, navigate } = useRouter()

  const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
    onClick?.(event)
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey ||
      rest.target ||
      rest.download !== undefined
    ) {
      return
    }
    const url = new URL(href, window.location.href)
    if (url.origin !== window.location.origin) return
    // A section on the current page: let the browser scroll natively.
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return
    event.preventDefault()
    navigate(`${url.pathname}${url.search}${url.hash}`)
  }

  return (
    <a href={href} onClick={handleClick} {...rest}>
      {children}
    </a>
  )
}
