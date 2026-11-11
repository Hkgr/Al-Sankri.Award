import { useSite } from '../content/useSite'
import { useLocale } from '../i18n/useLocale'

/**
 * The award's contact details for enquiries and cooperation: the email opens
 * the visitor's mail app, the number opens a WhatsApp chat in a new tab. The
 * number is isolated left-to-right so it never reorders inside Arabic text.
 */
export function ContactLinks({ className = '' }: { className?: string }) {
  const { contact } = useSite()
  const { t } = useLocale()

  return (
    <ul className={`contact-links ${className}`.trim()}>
      <li>
        <a className="contact-link" href={`mailto:${contact.email}`}>
          <svg className="contact-link__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="3" y="5" width="18" height="14" rx="2.5" />
            <path d="m4 7 8 6 8-6" />
          </svg>
          <span className="contact-link__text">
            <span className="contact-link__label">{t('contact.email')}</span>
            <bdi dir="ltr">{contact.email}</bdi>
          </span>
        </a>
      </li>
      <li>
        <a className="contact-link" href={contact.whatsapp} target="_blank" rel="noopener noreferrer">
          <svg className="contact-link__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4.5 19.5 5.6 16A8 8 0 1 1 8.4 18.6Z" />
            <path d="M9.2 9.3c.2 2.4 2.3 4.5 4.8 5 .5.1 1.2-.4 1.4-.9l.2-.6-1.6-.8-.7.7c-.9-.3-1.8-1.2-2.1-2.1l.7-.7-.8-1.6-.6.2c-.6.2-1 .9-.9 1.4Z" />
          </svg>
          <span className="contact-link__text">
            <span className="contact-link__label">{t('contact.whatsapp')}</span>
            <bdi dir="ltr">{contact.phone}</bdi>
          </span>
          <span className="visually-hidden"> {t('contact.whatsappHint')} {t('org.newWindow')}</span>
        </a>
      </li>
    </ul>
  )
}
