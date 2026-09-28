import { useEffect, useState } from 'react'
import { NavLink, Route, Routes } from 'react-router-dom'
import { api } from './lib/api'
import type { Health } from './lib/types'
import {  } from './components/ui'
import { cx } from './lib/styles'
import CustomerSupport from './pages/CustomerSupport'
import AdminDashboard from './pages/AdminDashboard'
import AdminCustomers from './pages/AdminCustomers'
import PolicyPage from './pages/PolicyPage'
import AdminGate from './components/AdminGate'

const links = [
  { to: '/', label: 'Customer support', end: true },
  { to: '/admin', label: 'Support dashboard', end: true },
  { to: '/admin/customers', label: 'CRM' },
  { to: '/policy', label: 'Refund policy' },
]

export default function App() {
  const [health, setHealth] = useState<Health | null>(null)
  const [healthError, setHealthError] = useState(false)

  useEffect(() => {
    api.health().then(setHealth).catch(() => setHealthError(true))
  }, [])

  return (
    <div className="flex min-h-full flex-col">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
          <div className="mr-auto flex items-center gap-2 sm:mr-0">
            <div className="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">W</div>
            <div className="leading-tight">
              <div className="text-sm font-semibold text-slate-900">Worknoon</div>
              <div className="text-[11px] text-slate-500">Refund Desk</div>
            </div>
          </div>
          <nav className="order-last -mx-1 flex w-full gap-1 overflow-x-auto sm:order-none sm:mx-0 sm:w-auto sm:flex-1">
            {links.map((l) => (
              <NavLink
                key={l.to}
                to={l.to}
                end={l.end}
                className={({ isActive }) =>
                  cx('whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition',
                    isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900')
                }
              >
                {l.label}
              </NavLink>
            ))}
          </nav>
          <AiStatus health={health} error={healthError} />
        </div>
      </header>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">
        <Routes>
          <Route path="/" element={<CustomerSupport />} />
          <Route path="/admin" element={<AdminGate><AdminDashboard /></AdminGate>} />
          <Route path="/admin/customers" element={<AdminGate><AdminCustomers /></AdminGate>} />
          <Route path="/policy" element={<PolicyPage />} />
          <Route path="*" element={<p className="text-slate-500">Page not found.</p>} />
        </Routes>
      </main>
    </div>
  )
}

function AiStatus({ health, error }: { health: Health | null; error: boolean }) {
  if (error) {
    return <span className="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700">API offline</span>
  }
  if (!health) return null
  const llm = health.ai.mode === 'llm'
  return (
    <span
      title={llm ? 'Requests are classified and answered with Claude.' : 'No ANTHROPIC_API_KEY set: deterministic keyword fallback is used.'}
      className={cx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
        llm ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800')}
    >
      <span className={cx('h-1.5 w-1.5 rounded-full', llm ? 'bg-emerald-500' : 'bg-amber-500')} />
      {llm ? `AI: ${health.ai.model}` : 'AI: offline fallback'}
    </span>
  )
}
