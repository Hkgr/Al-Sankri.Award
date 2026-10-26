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

  useEffect(() => {
    const onPopState = () => setLocation(currentLocation())
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  const navigate = useCallback((to: string, options: NavigateOptions = {}) => {
    const url = new URL(to, window.location.href)
    if (url.origin !== window.location.origin) {
      window.location.assign(url.href)
      return
    }
    const target = `${url.pathname}${url.search}${url.hash}`
    if (options.replace) {
      window.history.replaceState(null, '', target)
    } else {
      window.history.pushState(null, '', target)
    }
    setLocation({ pathname: url.pathname, search: url.search, hash: url.hash })
  }, [])

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

  const value = useMemo(() => ({ location, navigate }), [location, navigate])

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
