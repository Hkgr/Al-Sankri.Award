import { useCallback, useEffect, useState } from 'react'
import type { User } from '../../auth/useAuth'
import { FormAlert } from '../../components/forms/FormControls'
import { api, errorMessage } from '../../lib/api'
import { Link } from '../../lib/router'
import { usePageTitle } from '../auth/usePageTitle'
import { contextHeader } from '../candidate/candidateApi'
import type { JudgeProjectRow } from './judgeApi'

/** The judge's assigned projects in their main domain. */
export function ProjectsPage({ user }: { user: User }) {
  usePageTitle('التحكيم')
  const [projects, setProjects] = useState<JudgeProjectRow[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setProjects((await api.get<{ data: JudgeProjectRow[] }>('/api/judge/projects', contextHeader(user))).data)
    } catch (failure) {
      setError(errorMessage(failure))
    }
  }, [user])

  useEffect(() => {
    // Fetching on mount: the state is set once the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return (
    <div className="candidate-page">
      <header className="candidate-page__header">
        <p className="eyebrow">بوابة الحكم</p>
        <h1 className="candidate-page__title">التحكيم</h1>
        <p className="candidate-page__intro">مشاريع محورك. ضع علامة من 100 مع الملاحظات والتوصيات، ثم أرسلها إلى الإدارة.</p>
      </header>

      {error ? (
        <FormAlert tone="error">
          <p>{error}</p>
          <p>
            <button type="button" className="button button--secondary" onClick={() => void load()}>
              إعادة المحاولة
            </button>
          </p>
        </FormAlert>
      ) : !projects ? (
        <p className="candidate-loading" role="status">
          جارٍ تحميل المشاريع…
        </p>
      ) : projects.length === 0 ? (
        <FormAlert tone="info">
          <p>لا توجد مشاريع للتحكيم بعد. تظهر بعد اعتماد لجنة محورك ووصول ملفات الترشح المرسلة.</p>
        </FormAlert>
      ) : (
        <ul className="judge-list">
          {projects.map((project) => (
            <li key={project.id}>
              <Link href={`/judge/projects/${project.id}`}>
                <span className="judge-list__title">
                  <bdi>{project.title}</bdi>
                </span>
                <span className="judge-list__meta">
                  {project.domain.name}
                  {project.subcategory ? ` · ${project.subcategory.name}` : ''}
                </span>
                <span className={project.evaluation.status === 'sent' ? 'judge-sent' : 'judge-list__meta'}>
                  {project.evaluation.status === 'sent'
                    ? `أُرسلت إلى الإدارة · ${project.evaluation.score} من 100${project.evaluation.excluded ? ' · استُبعد المشارك' : ''}`
                    : 'بانتظار علامتك'}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
