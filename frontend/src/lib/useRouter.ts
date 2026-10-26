import { createContext, useContext } from 'react'

export type Location = {
  pathname: string
  search: string
  hash: string
}

export type NavigateOptions = { replace?: boolean }

export type RouterValue = {
  location: Location
  navigate: (to: string, options?: NavigateOptions) => void
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
