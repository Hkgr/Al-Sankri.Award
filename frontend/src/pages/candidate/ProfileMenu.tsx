import { useEffect, useId, useRef, useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import type { User } from '../../auth/useAuth'
import { errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { useRouter } from '../../lib/useRouter'
import { initialsOf } from './initials'

/**
 * The account menu: a disclosure button that reveals a short list of links.
 * Escape closes it and returns focus to the button; so does clicking
 * outside or moving focus out of it.
 */
export function ProfileMenu({ user }: { user: User }) {
  const { logout } = useAuth()
  const { navigate, confirmLeave } = useRouter()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      buttonRef.current?.focus()
    }
    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open])

  const onLogout = async () => {
    if (busy || !confirmLeave()) return
    setBusy(true)
    setError(null)
    try {
      await logout()
      navigate('/', { replace: true })
    } catch (failure) {
      setError(errorMessage(failure))
      setBusy(false)
    }
  }

  return (
    <div
      className="profile-menu"
      ref={containerRef}
      onBlur={(event) => {
        if (open && !containerRef.current?.contains(event.relatedTarget as Node | null)) setOpen(false)
      }}
    >
      <button
        ref={buttonRef}
        type="button"
        className="profile-menu__button"
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((shown) => !shown)}
      >
        <span className="profile-menu__avatar" aria-hidden="true">
          {initialsOf(user.name)}
        </span>
        <span className="profile-menu__identity">
          <span className="profile-menu__name" dir="auto">
            {user.name}
          </span>
          <span className="profile-menu__role">مترشح</span>
        </span>
        <svg className="profile-menu__chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d={open ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6'} />
        </svg>
        <span className="visually-hidden">قائمة الحساب</span>
      </button>

      <div id={menuId} className="profile-menu__panel" hidden={!open}>
        {/* On narrow screens the button shows only the avatar; the panel names the account. */}
        <p className="profile-menu__header" aria-hidden="true">
          <span className="profile-menu__name" dir="auto">
            {user.name}
          </span>
          <span className="profile-menu__role">مترشح</span>
        </p>
        <ul className="profile-menu__list">
          <li>
            <Link className="profile-menu__item" href="/dashboard" onClick={() => setOpen(false)}>
              لوحة المترشح
            </Link>
          </li>
          <li>
            <Link className="profile-menu__item" href="/account" onClick={() => setOpen(false)}>
              إعدادات الحساب
            </Link>
          </li>
          <li>
            <Link className="profile-menu__item" href="/" onClick={() => setOpen(false)}>
              العودة إلى الموقع العام
            </Link>
          </li>
          <li>
            <button type="button" className="profile-menu__item" onClick={onLogout} aria-busy={busy || undefined} aria-disabled={busy || undefined}>
              {busy ? 'جارٍ تسجيل الخروج…' : 'تسجيل الخروج'}
            </button>
          </li>
        </ul>
        {error && (
          <p className="profile-menu__error" role="alert">
            {error}
          </p>
        )}
      </div>
    </div>
  )
}
