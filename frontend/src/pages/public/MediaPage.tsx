import { brandLogoDownloads } from '../../content/brandAssets'
import { useSite } from '../../content/useSite'
import { useLocale } from '../../i18n/useLocale'
import { usePageMeta } from '../../lib/usePageMeta'
import { PageHeader } from './PageHeader'
import './public.css'

/**
 * The Media Center: the award's news and official statements (none are
 * published yet, and none are invented) and the official logos for media use.
 * The implementing and participating organizations close every page, in the footer.
 */
export function MediaPage() {
  const { award, pages } = useSite()
  const { t } = useLocale()
  const page = pages[4]
  usePageMeta(page.label, page.description)

  return (
    <>
      <PageHeader trail={[{ href: '/', label: t('nav.home') }, { href: page.href, label: page.label }]} eyebrow={t('media.eyebrow')} title={t('media.title')}>
        <p>{t('media.intro')}</p>
      </PageHeader>

      <section className="section" aria-labelledby="media-news-title">
        <div className="container">
          <h2 id="media-news-title" className="section__title">
            {t('media.newsTitle')}
          </h2>
          <p className="media-empty">{t('media.newsEmpty')}</p>
        </div>
      </section>

      <section className="section section--subtle" aria-labelledby="media-kit-title">
        <div className="container">
          <h2 id="media-kit-title" className="section__title">
            {t('media.kitTitle')}
          </h2>
          <p className="prose">{t('media.kitText')}</p>
          <ul className="media-kit">
            {brandLogoDownloads.map((item) => (
              <li key={item.file} className={`media-kit__item media-kit__item--${item.surface}`}>
                {/* The official artwork on its approved surface, unchanged. */}
                <div className="media-kit__preview">
                  <img src={item.src} alt={award.name} width={800} height={259} loading="lazy" decoding="async" />
                </div>
                <p className="media-kit__label">
                  {t(item.locale === 'ar' ? 'media.kitArabic' : 'media.kitEnglish')} · {t(item.surface === 'light' ? 'media.kitLight' : 'media.kitDark')}
                </p>
                <a className="media-kit__download" href={item.src} download={item.file}>
                  {t('media.download')} <span className="media-kit__format">{t('media.downloadFormat')}</span>
                </a>
              </li>
            ))}
          </ul>
        </div>
      </section>
    </>
  )
}
