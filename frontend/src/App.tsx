import { SiteFooter } from './components/layout/SiteFooter'
import { SiteHeader } from './components/layout/SiteHeader'
import { HomePage } from './pages/home/HomePage'

function App() {
  return (
    <>
      <SiteHeader />
      <main id="main" tabIndex={-1}>
        <HomePage />
      </main>
      <SiteFooter />
    </>
  )
}

export default App
