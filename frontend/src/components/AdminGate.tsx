import { useState, type FormEvent, type ReactNode } from 'react'
import { adminToken, api, ApiError } from '../lib/api'
import { Alert, Button, Card, Field } from './ui'
import { inputClass } from '../lib/styles'

/**
 * Asks for the shared support token once per browser session. The backend
 * is the real guard; this only avoids a wall of 401s.
 */
export default function AdminGate({ children }: { children: ReactNode }) {
  const [authed, setAuthed] = useState(() => adminToken.get() !== '')
  const [token, setToken] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError(null)
    adminToken.set(token.trim())
    try {
      await api.admin.stats()
      setAuthed(true)
    } catch (err) {
      adminToken.clear()
      setError(err instanceof ApiError ? err.message : 'Could not sign in.')
    } finally {
      setLoading(false)
    }
  }

  if (authed) {
    return <AuthContext onSignOut={() => { adminToken.clear(); setAuthed(false) }}>{children}</AuthContext>
  }

  return (
    <div className="grid place-items-center py-16">
      <Card className="w-full max-w-sm p-6">
        <h1 className="text-lg font-semibold text-slate-900">Support dashboard</h1>
        <p className="mb-4 mt-1 text-sm text-slate-500">Enter the support access token (<code className="rounded bg-slate-100 px-1">ADMIN_TOKEN</code>).</p>
        <form onSubmit={submit} className="space-y-3">
          <Field label="Access token" hint="The demo default is worknoon-admin.">
            <input className={inputClass} type="password" value={token} onChange={(e) => setToken(e.target.value)} required autoFocus />
          </Field>
          {error && <Alert>{error}</Alert>}
          <Button type="submit" className="w-full" loading={loading}>Sign in</Button>
        </form>
      </Card>
    </div>
  )
}

function AuthContext({ children, onSignOut }: { children: ReactNode; onSignOut: () => void }) {
  return (
    <div>
      <div className="mb-2 flex justify-end">
        <button onClick={onSignOut} className="text-xs text-slate-500 hover:text-slate-800">Sign out</button>
      </div>
      {children}
    </div>
  )
}
