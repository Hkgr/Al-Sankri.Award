import { useCallback, useEffect, useId, useRef, useState } from 'react'
import type { DragEvent, ReactNode } from 'react'
import type { User } from '../../../auth/useAuth'
import { FormAlert, StatusIcon, TextField } from '../../../components/forms/FormControls'
import { ApiError, api, errorMessage, NetworkError } from '../../../lib/api'
import { Link } from '../../../lib/router'
import { useLeaveWarning, useRouter } from '../../../lib/useRouter'
import { usePageTitle } from '../../auth/usePageTitle'
import { contextHeader, formatDamascusDateTime } from '../candidateApi'
import { useAccountFailure } from '../useCandidateRequests'
import { AttachmentList, LinkList } from './AttachmentList'
import type { Attachment, AttachmentKind, Documentation, DocumentationLink, DocumentationState } from './documentationApi'
import { formatBytes, journeyStateUrl, KIND_RULES, precheck, SECTION_TITLES } from './documentationApi'
import { useLocale } from '../../../i18n/useLocale'
import { awardTerms } from '../../../content/awardTerms'

const STATE_URL = '/api/candidate/documentation'

type Loaded = { kind: 'state'; state: DocumentationState } | { kind: 'stage_1_required' }

/**
 * Stage 3: the explanatory document (required), supporting documents,
 * videos and links. Files are uploaded one per request and kept as soon as
 * the server confirms them; until the final submission they can be
 * replaced or removed. Afterwards everything is shown read-only.
 */
export function DocumentationPage({ user }: { user: User }) {
  const { t, locale } = useLocale()
  usePageTitle(t('ملفات العمل وتوثيقه'))
  const handleAccountFailure = useAccountFailure()
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      setLoaded({ kind: 'state', state: await api.get<DocumentationState>(STATE_URL, contextHeader(user)) })
    } catch (error) {
      if (error instanceof ApiError && error.code === 'basic_information_required') {
        setLoaded({ kind: 'stage_1_required' })
        return
      }
      if (!handleAccountFailure(error)) setLoadError(errorMessage(error))
    }
  }, [handleAccountFailure, user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const state = loaded?.kind === 'state' ? loaded.state : null
  const doc = state?.data ?? null

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">{t('المرحلة 3 من ملف الترشح')}</p>
        <h1 className="candidate-page__title">{t('ملفات العمل وتوثيقه')}</h1>
        <p className="candidate-page__intro">
          {doc?.submission
            ? t('أُرسلت المشاركة، وهذه ملفاتها وروابطها كما أُرسلت. هي متاحة للعرض فقط.')
            : t('ارفع وثيقة تشرح العمل المرشّح، وأضف إن شئت وثائق وفيديوهات وروابط توثّقه.')}
        </p>
        <p className="note">{awardTerms[locale].general[6].text} <Link href="/eligibility#conditions-title">{t('eligibility.conditions')}</Link></p>
      </header>

      {loadError ? (
        <FormAlert tone="error">
          <p>{loadError}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              {t('إعادة المحاولة')}
            </button>
          </p>
        </FormAlert>
      ) : !loaded ? (
        <p className="candidate-loading" role="status">
          {t('جارٍ تحميل الملفات…')}
        </p>
      ) : loaded.kind === 'stage_1_required' ? (
        <FormAlert tone="info">
          <p>{t('تُتاح هذه المرحلة بعد تأكيد حفظ البيانات الأساسية (المرحلة 1) وفئة الترشح وبيانات العمل (المرحلة 2).')}</p>
          <p>
            <Link href="/dashboard/basic-information">{t('الانتقال إلى البيانات الأساسية')}</Link>
          </p>
        </FormAlert>
      ) : !doc || !state?.journey_key ? (
        <FormAlert tone="info">
          <p>{t('تُتاح هذه المرحلة بعد تأكيد حفظ فئة الترشح وبيانات العمل (المرحلة 2).')}</p>
          <p>
            <Link href="/dashboard/nomination-details">{t('الانتقال إلى فئة الترشح وبيانات العمل')}</Link>
          </p>
        </FormAlert>
      ) : doc.editable ? (
        <DocumentationEditor
          user={user}
          journeyKey={state.journey_key}
          doc={doc}
          onState={(next) => setLoaded({ kind: 'state', state: next })}
          onReload={() => void load()}
        />
      ) : (
        <ReadOnlyDocumentation user={user} journeyKey={state.journey_key} doc={doc} />
      )}
    </div>
  )
}

function ReadOnlyDocumentation({ user, journeyKey, doc }: { user: User; journeyKey: string; doc: Documentation }) {
  const { t, locale } = useLocale()
  return (
    <>
      {doc.submission ? (
        <div className="locked-banner">
          <StatusIcon tone="success" />
          <div>
            <p className="locked-banner__title">{t('تم إرسال المشاركة')}</p>
            <p>
              {doc.submission.reference_number ? <>{t('الرقم المرجعي')} <bdi dir="ltr">{doc.submission.reference_number}</bdi>، </> : t('progress.referenceUnavailable')}{' '}
              {doc.submission.submitted_at ? t('أُرسلت في {when} بتوقيت دمشق. لا يمكن إضافة ملفات أو تعديلها بعد الإرسال.', { when: formatDamascusDateTime(doc.submission.submitted_at, locale) }) : t('progress.unknownDate')}
            </p>
          </div>
        </div>
      ) : (
        <FormAlert tone="info">
          <p>{t('هذه المرحلة غير متاحة للتعديل حالياً: باب الترشح لهذه الدورة غير مفتوح. ما حُفظ من ملفات وروابط ظاهر أدناه.')}</p>
        </FormAlert>
      )}
      {(['explanatory', 'supporting', 'video'] as AttachmentKind[]).map((kind) => (
        <section key={kind} className="doc-section" aria-labelledby={`doc-${kind}-title`}>
          <h2 id={`doc-${kind}-title`} className="doc-section__title">
            {t(SECTION_TITLES[kind])}
          </h2>
          <AttachmentList attachments={doc.attachments.filter((item) => item.kind === kind)} user={user} journeyKey={journeyKey} emptyText={t('لم يُضف شيء.')} />
        </section>
      ))}
      <section className="doc-section" aria-labelledby="doc-links-title">
        <h2 id="doc-links-title" className="doc-section__title">
          {t('روابط توثيق إضافية')}
        </h2>
        <LinkList links={doc.links} emptyText={t('لم تُضف روابط.')} />
      </section>
      <p className="candidate-page__back">
        <Link href={doc.submission ? '/dashboard/review' : '/dashboard'}>{doc.submission ? t('عرض المشاركة المرسلة') : t('العودة إلى لوحة المترشح')}</Link>
      </p>
    </>
  )
}

type Pending = {
  key: string
  kind: AttachmentKind
  file: File
  title: string
  operationId: string
  status: 'staged' | 'uploading' | 'failed' | 'unconfirmed'
  error?: string
  /** False when sending the same file again cannot help (its type, size or content was refused). */
  retryable?: boolean
}

function DocumentationEditor({
  user,
  journeyKey,
  doc,
  onState,
  onReload,
}: {
  user: User
  journeyKey: string
  doc: Documentation
  onState: (state: DocumentationState) => void
  onReload: () => void
}) {
  const { t } = useLocale()
  const { navigate } = useRouter()
  const [pending, setPending] = useState<Pending[]>([])
  const [links, setLinks] = useState<DocumentationLink[]>(doc.links)
  const [savedLinks, setSavedLinks] = useState<DocumentationLink[]>(doc.links)
  const [linkErrors, setLinkErrors] = useState<Record<string, string>>({})
  const [savingLinks, setSavingLinks] = useState(false)
  const [removing, setRemoving] = useState<number | null>(null)
  const [completing, setCompleting] = useState(false)
  const [alert, setAlert] = useState<{ tone: 'error' | 'info' | 'success'; content: ReactNode } | null>(null)

  const linksDirty = JSON.stringify(links) !== JSON.stringify(savedLinks)
  const unsettled = pending.length > 0
  const hasExplanatory = doc.attachments.some((item) => item.kind === 'explanatory')

  useLeaveWarning(
    pending.some((row) => row.status === 'uploading' || row.status === 'unconfirmed')
      ? t('لم يكتمل رفع بعض الملفات أو لم تتأكد نتيجته بعد. إذا غادرت الآن فقد لا تُحفظ. هل تريد المغادرة؟')
      : unsettled || linksDirty
        ? t('في هذه الصفحة ملفات لم تُرفع أو روابط لم تُحفظ بعد. إذا غادرتها فسيُحذف ذلك. هل تريد المغادرة؟')
        : null,
  )

  const setRow = (key: string, patch: Partial<Pending>) => setPending((rows) => rows.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  const dropRow = (key: string) => setPending((rows) => rows.filter((row) => row.key !== key))

  const accept = (state: DocumentationState) => {
    onState(state)
    if (state.data) {
      setSavedLinks(state.data.links)
    }
  }

  const addFiles = (kind: AttachmentKind, files: File[]) => {
    setAlert(null)
    const chosen = kind === 'explanatory' ? files.slice(0, 1) : files
    const rows: Pending[] = chosen.map((file) => {
      const refusal = precheck(kind, file)
      return {
        key: crypto.randomUUID(),
        kind,
        file,
        title: '',
        operationId: crypto.randomUUID(),
        status: refusal ? 'failed' : 'staged',
        error: refusal ?? undefined,
        retryable: refusal === null,
      }
    })
    setPending((current) => [...current, ...rows])
    // The explanatory document has no title: it is uploaded as soon as it is chosen.
    if (kind === 'explanatory') rows.filter((row) => row.status === 'staged').forEach((row) => void upload(row))
  }

  const upload = async (row: Pending) => {
    setRow(row.key, { status: 'uploading', error: undefined })
    const form = new FormData()
    form.append('kind', row.kind)
    if (row.title.trim()) form.append('title', row.title.trim())
    form.append('operation_id', row.operationId)
    form.append('account_context', user.account_context)
    form.append('journey_key', journeyKey)
    form.append('file', row.file)

    try {
      accept(await api.upload<DocumentationState>('/api/candidate/documentation/files', form))
      dropRow(row.key)
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        setRow(row.key, { status: 'failed', error: error.errors.file?.[0] ?? error.message, retryable: false })
        return
      }
      if (error instanceof ApiError && (error.code === 'application_submitted' || error.code === 'documentation_unavailable')) {
        setRow(row.key, { status: 'failed', error: error.message })
        onReload()
        return
      }
      if (error instanceof ApiError && error.status === 401) {
        setRow(row.key, { status: 'failed', error: t('انتهت جلستك، ولم يُرفع الملف. سجّل الدخول بالحساب نفسه في نافذة جديدة ثم أعد المحاولة.') })
        return
      }
      if (error instanceof NetworkError || (error instanceof ApiError && error.status >= 500)) {
        await reconcile(row)
        return
      }
      setRow(row.key, { status: 'failed', error: errorMessage(error) })
    }
  }

  /**
   * The answer was lost or timed out: the file may be stored anyway. The
   * server's list tells; a retry sends the same operation identifier, so it
   * never adds the file twice.
   */
  const reconcile = async (row: Pending) => {
    try {
      // This page's journey only: another cycle's list never stands in for it.
      const state = await api.get<DocumentationState>(journeyStateUrl('documentation', journeyKey), contextHeader(user))
      if (state.journey_key === journeyKey && state.data?.attachments.some((item) => item.operation_id === row.operationId)) {
        accept(state)
        dropRow(row.key)
        return
      }
    } catch {
      // Still unknown: say so below.
    }
    setRow(row.key, {
      status: 'unconfirmed',
      error: t('لم تصل نتيجة الرفع، ولا يظهر الملف بين الملفات المحفوظة حتى الآن. أعد المحاولة: إن كان قد حُفظ فلن يُضاف مرة ثانية.'),
    })
  }

  const remove = async (attachment: Attachment) => {
    setRemoving(attachment.id)
    setAlert(null)
    try {
      accept(await api.post<DocumentationState>('/api/candidate/documentation/files/remove', { attachment: attachment.id, account_context: user.account_context, journey_key: journeyKey }))
    } catch (error) {
      setAlert({ tone: 'error', content: <p>{errorMessage(error)}</p> })
      if (error instanceof ApiError && error.status === 409) onReload()
    } finally {
      setRemoving(null)
    }
  }

  const saveLinks = async () => {
    setSavingLinks(true)
    setLinkErrors({})
    setAlert(null)
    try {
      const state = await api.post<DocumentationState>('/api/candidate/documentation/links', { links, account_context: user.account_context, journey_key: journeyKey })
      accept(state)
      // As stored (titles with spacing tidied).
      setLinks(state.data?.links ?? [])
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        setLinkErrors(Object.fromEntries(Object.entries(error.errors).map(([field, list]) => [field, list[0]])))
        setAlert({ tone: 'error', content: <p>{t('لم تُحفظ الروابط: صحّح الحقول المشار إليها ثم احفظها من جديد.')}</p> })
      } else {
        setAlert({ tone: 'error', content: <p>{errorMessage(error)} {t('لم تُحفظ الروابط، وما زالت في الصفحة.')}</p> })
      }
    } finally {
      setSavingLinks(false)
    }
  }

  const complete = async () => {
    setCompleting(true)
    setAlert(null)
    try {
      await api.post('/api/candidate/documentation/confirm', { revision: doc.revision, account_context: user.account_context, journey_key: journeyKey })
      navigate('/dashboard/review')
    } catch (error) {
      if (error instanceof ApiError && error.code === 'documentation_changed') {
        accept(error.payload as unknown as DocumentationState)
      }
      setAlert({ tone: 'error', content: <p>{errorMessage(error)}</p> })
    } finally {
      setCompleting(false)
    }
  }

  const blocker = !hasExplanatory
    ? t('ارفع وثيقة شرح العمل أولاً.')
    : unsettled
      ? t('أكمل رفع الملفات المختارة أو أزلها أولاً.')
      : linksDirty
        ? t('احفظ تغييرات الروابط أولاً.')
        : null

  return (
    <>
      {alert && <FormAlert tone={alert.tone}>{alert.content}</FormAlert>}

      <section className="doc-section" aria-labelledby="doc-explanatory-title">
        <h2 id="doc-explanatory-title" className="doc-section__title">
          {t('وثيقة شرح العمل')} <span className="doc-section__required">{t('(مطلوبة)')}</span>
        </h2>
        <p className="doc-section__text">{t('وثيقة واحدة تشرح العمل المرشّح: فكرته، وما أُنجز، وأثره. يمكنك استبدالها حتى إرسال المشاركة.')}</p>
        <AttachmentList attachments={doc.attachments.filter((item) => item.kind === 'explanatory')} user={user} journeyKey={journeyKey} emptyText={t('لم تُرفع وثيقة شرح العمل بعد.')} />
        <FilePicker kind="explanatory" label={hasExplanatory ? t('استبدال الوثيقة') : t('اختيار الوثيقة')} multiple={false} onFiles={(files) => addFiles('explanatory', files)} />
        <PendingRows rows={pending.filter((row) => row.kind === 'explanatory')} onUpload={upload} onDrop={dropRow} onTitle={(key, title) => setRow(key, { title })} />
      </section>

      {(['supporting', 'video'] as const).map((kind) => (
        <section key={kind} className="doc-section" aria-labelledby={`doc-${kind}-title`}>
          <h2 id={`doc-${kind}-title`} className="doc-section__title">
            {t(SECTION_TITLES[kind])} <span className="doc-section__optional">{t('(اختيارية)')}</span>
          </h2>
          <p className="doc-section__text">
            {kind === 'supporting'
              ? t('تقارير أو شهادات أو وثائق أخرى تدعم ما ورد في وثيقة الشرح. لكل وثيقة عنوان أو وصف قصير اختياري.')
              : t('مقاطع فيديو تُظهر العمل وأثره. تُعرض كما رُفعت، دون تعديل أو تحويل.')}
          </p>
          <AttachmentList
            attachments={doc.attachments.filter((item) => item.kind === kind)}
            user={user}
            journeyKey={journeyKey}
            onRemove={(attachment) => void remove(attachment)}
            busyId={removing}
            emptyText={kind === 'supporting' ? t('لم تُضف وثائق داعمة.') : t('لم تُضف فيديوهات.')}
          />
          <FilePicker kind={kind} label={kind === 'supporting' ? t('إضافة وثائق') : t('إضافة فيديوهات')} multiple onFiles={(files) => addFiles(kind, files)} />
          <PendingRows rows={pending.filter((row) => row.kind === kind)} onUpload={upload} onDrop={dropRow} onTitle={(key, title) => setRow(key, { title })} />
        </section>
      ))}

      <section className="doc-section" aria-labelledby="doc-links-title">
        <h2 id="doc-links-title" className="doc-section__title">
          {t('روابط توثيق إضافية')} <span className="doc-section__optional">{t('(اختيارية)')}</span>
        </h2>
        <p className="doc-section__text">
          {t('روابط إلى فيديوهات أو مواقع أو مواد أخرى تخص العمل، تبدأ بـ')} <bdi dir="ltr">https://</bdi>.{' '}
          {t('تُحفظ كما كُتبت؛ لا نجلب محتواها، وقد يتغيّر ذلك المحتوى أو يُحذف لاحقاً.')}
        </p>
        <div className="link-rows">
          {links.map((link, index) => (
            <div key={index} className="link-row">
              <TextField
                id={`doc-link-${index}-title`}
                label={t('عنوان الرابط {v}', { v: index + 1 })}
                value={link.title}
                onChange={(title) => setLinks((rows) => rows.map((row, at) => (at === index ? { ...row, title } : row)))}
                error={linkErrors[`links.${index}.title`]}
                maxLength={150}
                autoComplete="off"
                required
              />
              <TextField
                id={`doc-link-${index}-url`}
                label={t('الرابط {v}', { v: index + 1 })}
                type="url"
                value={link.url}
                onChange={(url) => setLinks((rows) => rows.map((row, at) => (at === index ? { ...row, url } : row)))}
                error={linkErrors[`links.${index}.url`]}
                hint={t('مثل https://example.org/video')}
                autoComplete="url"
                required
              />
              <p>
                <button type="button" className="button button--quiet button--small" onClick={() => setLinks((rows) => rows.filter((_, at) => at !== index))}>
                  {t('حذف الرابط {n}', { n: index + 1 })}
                </button>
              </p>
            </div>
          ))}
        </div>
        <div className="doc-section__actions">
          {links.length < 20 && (
            <button type="button" className="button button--secondary" onClick={() => setLinks((rows) => [...rows, { title: '', url: '' }])}>
              {t('إضافة رابط')}
            </button>
          )}
          {linksDirty && (
            <button type="button" className="button button--primary" onClick={() => void saveLinks()} disabled={savingLinks}>
              {savingLinks ? t('جارٍ حفظ الروابط…') : t('حفظ الروابط')}
            </button>
          )}
        </div>
        {linksDirty && <p className="doc-section__note">{t('في الروابط تغييرات لم تُحفظ بعد.')}</p>}
      </section>

      <div className="doc-complete">
        {doc.confirmed && !unsettled && !linksDirty && <p className="doc-section__note">{t('حُفظت هذه المرحلة. يمكنك متابعة المراجعة، أو تعديل الملفات ثم حفظها من جديد.')}</p>}
        {blocker && <p className="doc-section__note">{blocker}</p>}
        <button type="button" className="button button--primary" onClick={() => void complete()} disabled={blocker !== null || completing}>
          {completing ? t('جارٍ الحفظ…') : t('حفظ المرحلة والمتابعة إلى المراجعة')}
        </button>
      </div>
    </>
  )
}

/** An accessible file picker with an optional drop area. */
function FilePicker({ kind, label, multiple, onFiles }: { kind: AttachmentKind; label: string; multiple: boolean; onFiles: (files: File[]) => void }) {
  const { t } = useLocale()
  const id = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [over, setOver] = useState(false)
  const rules = KIND_RULES[kind]

  const onDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault()
    setOver(false)
    const files = Array.from(event.dataTransfer.files)
    if (files.length > 0) onFiles(files)
  }

  return (
    <div
      className={`drop-zone${over ? ' drop-zone--over' : ''}`}
      onDragOver={(event) => {
        event.preventDefault()
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={onDrop}
    >
      <input
        ref={inputRef}
        id={id}
        className="visually-hidden"
        type="file"
        accept={rules.accept}
        multiple={multiple}
        aria-describedby={`${id}-hint`}
        onChange={(event) => {
          const files = Array.from(event.target.files ?? [])
          event.target.value = ''
          if (files.length > 0) onFiles(files)
        }}
      />
      <label htmlFor={id} className="button button--secondary drop-zone__button">
        {label}
      </label>
      <p id={`${id}-hint`} className="drop-zone__hint">
        {t(multiple ? '{formats}، حتى {size} لكل ملف. يمكنك أيضاً سحب الملفات وإفلاتها هنا.' : '{formats}، حتى {size}. يمكنك أيضاً سحب الملف وإفلاته هنا.', {
          formats: t(rules.formats),
          size: formatBytes(rules.maxBytes),
        })}
      </p>
    </div>
  )
}

/** Files chosen but not stored yet: their honest state, title, upload and retry. */
function PendingRows({
  rows,
  onUpload,
  onDrop,
  onTitle,
}: {
  rows: Pending[]
  onUpload: (row: Pending) => Promise<void>
  onDrop: (key: string) => void
  onTitle: (key: string, title: string) => void
}) {
  const { t } = useLocale()
  if (rows.length === 0) return null

  return (
    <ul className="attachment-list attachment-list--pending">
      {rows.map((row) => (
        <li key={row.key} className={`attachment attachment--${row.status}`}>
          <div className="attachment__main">
            <p className="attachment__name">
              <bdi>{row.file.name}</bdi>
            </p>
            <p className="attachment__meta">
              <bdi>{formatBytes(row.file.size)}</bdi>
            </p>
            <p className="attachment__status" role="status">
              <StatusIcon tone={row.status === 'failed' ? 'error' : 'info'} />
              <span>
                {row.status === 'staged' ? t('لم يُرفع بعد') : row.status === 'uploading' ? t('جارٍ الرفع…') : row.status === 'unconfirmed' ? t('نتيجة الرفع غير مؤكدة') : t('لم يُرفع')}
              </span>
            </p>
            {row.error && <p className="attachment__error">{row.error}</p>}
            {row.kind !== 'explanatory' && row.status === 'staged' && (
              <TextField id={`title-${row.key}`} label={t('عنوان أو وصف قصير')} optional value={row.title} onChange={(title) => onTitle(row.key, title)} maxLength={150} autoComplete="off" />
            )}
          </div>
          <div className="attachment__actions">
            {row.status === 'staged' && (
              <button type="button" className="button button--primary button--small" onClick={() => void onUpload(row)}>
                {t('رفع الملف')}
              </button>
            )}
            {row.status === 'unconfirmed' && (
              <button type="button" className="button button--primary button--small" onClick={() => void onUpload(row)}>
                {t('إعادة المحاولة')}
              </button>
            )}
            {row.status === 'failed' && row.retryable !== false && (
              <button type="button" className="button button--secondary button--small" onClick={() => void onUpload(row)}>
                {t('إعادة المحاولة')}
              </button>
            )}
            {row.status !== 'uploading' && (
              <button type="button" className="button button--quiet button--small" onClick={() => onDrop(row.key)}>
                {row.status === 'unconfirmed' ? t('إخفاء') : t('إزالة')}
                <span className="visually-hidden"> {row.file.name}</span>
              </button>
            )}
          </div>
        </li>
      ))}
    </ul>
  )
}
