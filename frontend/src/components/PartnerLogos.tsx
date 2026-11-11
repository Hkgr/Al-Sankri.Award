import { partnerLogos } from '../content/brandAssets'
import { useSite } from '../content/useSite'
import { useLocale } from '../i18n/useLocale'

/**
 * The implementing and participating organizations: each supplied logo on its
 * own light tile (their artwork is dark ink, so never on the deep green
 * directly), with its name and role in text, linking to its website or page in
 * a new tab. The logo is decorative: the visible name labels the link.
 */
export function PartnerLogos({ tone, className = '' }: { tone: 'on-dark' | 'on-light'; className?: string }) {
  const { partners } = useSite()
  const { t } = useLocale()

  return (
    <ul className={`partners partners--${tone} ${className}`.trim()}>
      {partners.map((partner) => {
        const logo = partnerLogos[partner.key]
        return (
          <li key={partner.key} className={`partners__item partners__item--${partner.role}`}>
            <a className="partner" href={partner.url} target="_blank" rel="noopener noreferrer">
              <span className="partner__logo">
                <img src={logo.src} width={logo.width} height={logo.height} alt="" loading="lazy" decoding="async" />
              </span>
              <span className="partner__text">
                <span className="partner__role">{t(`partners.${partner.role}`)}</span>
                <span className="partner__name">{partner.name}</span>
              </span>
              <span className="visually-hidden"> {t('org.newWindow')}</span>
            </a>
          </li>
        )
      })}
    </ul>
  )
}
