import { useEffect, useState } from 'react'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { api } from '../lib/api'
import { Alert, Card, Spinner } from '../components/ui'

export default function PolicyPage() {
  const [markdown, setMarkdown] = useState<string | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    api.policy().then((p) => setMarkdown(p.markdown)).catch(() => setError(true))
  }, [])

  return (
    <Card className="mx-auto max-w-3xl px-6 py-8 sm:px-10">
      {error && <Alert>Could not load the policy.</Alert>}
      {!markdown && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner className="h-6 w-6" /></div>}
      {markdown && <article className="policy"><Markdown remarkPlugins={[remarkGfm]}>{markdown}</Markdown></article>}
      <p className="mt-8 border-t border-slate-100 pt-4 text-xs text-slate-400">
        The numbers in this document are rendered from the same configuration the policy engine enforces.
      </p>
    </Card>
  )
}
