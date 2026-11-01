import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { useLocale } from '../../i18n/useLocale'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { usePageTitle } from '../auth/usePageTitle'
import { contextHeader } from '../candidate/candidateApi'
import type { JudgeProjectRow, JudgeProjectsAnswer } from './judgeApi'
import { JudgeWindowNotice } from './JudgeWindowNotice'

/** The judge's assigned projects in their main domain. */
export function ProjectsPage({ user }: { user: User }) {
  const { t } = useLocale()
  usePageTitle(t('judge.title'))
  const [answer, setAnswer] = useState<JudgeProjectsAnswer | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setAnswer(await api.get<JudgeProjectsAnswer>('/api/judge/projects', contextHeader(user)))
    } catch (failure) {
      setError(errorMessage(failure))
    }
  }, [user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const evaluationLabel = (project: JudgeProjectRow) =>
    project.evaluation.status === 'sent'
      ? project.evaluation.excluded
        ? t('judge.sentExcluded')
        : t('judge.sentScore', { score: project.evaluation.score ?? '' })
      : t('judge.waiting')

  const body = (project: JudgeProjectRow) => (
    <>
      <span className="judge-list__title">
        <bdi>{project.title}</bdi>
      </span>
      <span className="judge-list__meta">
        {project.domain.name}
        {project.subcategory ? ` · ${project.subcategory.name}` : ''}
      </span>
      <span className={project.evaluation.status === 'sent' ? 'judge-sent' : 'judge-list__meta'}>{evaluationLabel(project)}</span>
      {!project.judging_open && <span className="judge-list__meta judge-list__locked">{t('judge.locked')}</span>}
    </>
  )

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">{t('judge.eyebrow')}</p>
        <h1 className="candidate-page__title">{t('judge.title')}</h1>
        <p className="candidate-page__intro">{t('judge.intro')}</p>
      </header>

      {error ? (
        <FormAlert tone="error">
          <p>{error}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              {t('common.retry')}
            </button>
          </p>
        </FormAlert>
      ) : !answer ? (
        <p className="candidate-loading" role="status">
          {t('judge.loading')}
        </p>
      ) : (
        <>
          <JudgeWindowNotice window={answer.window} />
          {answer.data.length === 0 ? (
            <FormAlert tone="info">
              <p>{t('judge.empty')}</p>
            </FormAlert>
          ) : (
            <ul className="judge-list">
              {answer.data.map((project) => (
                <li key={project.id}>
                  {project.judging_open ? (
                    <Link href={`/judge/projects/${project.id}`}>{body(project)}</Link>
                  ) : (
                    <div className="judge-list__item judge-list__item--locked">{body(project)}</div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}
