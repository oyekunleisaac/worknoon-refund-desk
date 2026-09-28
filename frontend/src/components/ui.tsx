import type { ButtonHTMLAttributes, ReactNode } from 'react'
import type { Decision, RequestStatus } from '../lib/types'
import { cx } from '../lib/styles'


const decisionStyles: Record<Decision, string> = {
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  denied: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  escalated: 'bg-amber-50 text-amber-800 ring-amber-600/25',
}

const decisionIcons: Record<Decision, string> = { approved: '✓', denied: '✕', escalated: '↗' }

export function DecisionBadge({ decision, size = 'sm' }: { decision: Decision | null; size?: 'sm' | 'lg' }) {
  if (!decision) return <span className="text-xs text-slate-400">Processing…</span>
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1 rounded-full font-medium ring-1 ring-inset',
        size === 'lg' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs',
        decisionStyles[decision],
      )}
    >
      <span aria-hidden>{decisionIcons[decision]}</span>
      {decision.charAt(0).toUpperCase() + decision.slice(1)}
    </span>
  )
}

const statusLabels: Record<RequestStatus, [string, string]> = {
  processing: ['Processing', 'bg-slate-100 text-slate-600'],
  resolved: ['Auto-resolved', 'bg-slate-100 text-slate-600'],
  pending_review: ['Needs review', 'bg-amber-100 text-amber-800'],
  reviewed: ['Human reviewed', 'bg-brand-50 text-brand-700'],
  failed: ['Failed – review', 'bg-rose-100 text-rose-700'],
}

export function StatusBadge({ status }: { status: RequestStatus }) {
  const [label, style] = statusLabels[status]
  return <span className={cx('rounded px-1.5 py-0.5 text-[11px] font-medium', style)}>{label}</span>
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cx('rounded-xl border border-slate-200 bg-white shadow-sm', className)}>{children}</div>
}

export function SectionTitle({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-2 flex items-center justify-between">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</h3>
      {aside}
    </div>
  )
}

type Variant = 'primary' | 'secondary' | 'danger' | 'success' | 'ghost'
const variants: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 shadow-sm',
  secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
  danger: 'bg-rose-600 text-white hover:bg-rose-700 shadow-sm',
  success: 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm',
  ghost: 'text-slate-600 hover:bg-slate-100',
}

export function Button({
  variant = 'primary', loading, className, children, disabled, ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; loading?: boolean }) {
  return (
    <button
      {...props}
      disabled={disabled || loading}
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
        'disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
        variants[variant],
        className,
      )}
    >
      {loading && <Spinner />}
      {children}
    </button>
  )
}

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={cx('h-4 w-4 animate-spin', className)} viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity=".25" strokeWidth="4" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
    </svg>
  )
}

export function Field({ label, error, children, hint }: { label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
    </label>
  )
}


export function Alert({ tone = 'error', children }: { tone?: 'error' | 'info' | 'warning'; children: ReactNode }) {
  const styles = {
    error: 'bg-rose-50 text-rose-800 ring-rose-200',
    info: 'bg-brand-50 text-brand-700 ring-brand-100',
    warning: 'bg-amber-50 text-amber-900 ring-amber-200',
  }
  return <div className={cx('rounded-lg px-3 py-2 text-sm ring-1 ring-inset', styles[tone])}>{children}</div>
}

export function Tag({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'rose' | 'amber' | 'emerald' | 'brand' }) {
  const styles = {
    slate: 'bg-slate-100 text-slate-600',
    rose: 'bg-rose-100 text-rose-700',
    amber: 'bg-amber-100 text-amber-800',
    emerald: 'bg-emerald-100 text-emerald-700',
    brand: 'bg-brand-100 text-brand-700',
  }
  return <span className={cx('inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium', styles[tone])}>{children}</span>
}
