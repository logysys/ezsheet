import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';

/**
 * EZ-LOCK-20261017 P2: owner panel for a PRIVATE slug — who else may open it.
 * Uses the existing owner endpoints:
 *   GET/PATCH /ai/conversation/{id}/private-settings     (unlock modes, password, sign out all)
 *   GET       /ai/conversation/{id}/private-access-logs   (verified viewers)
 *   GET/PATCH /ai/conversation/{id}/password-requests     ("Request password" inbox)
 * The password is write-only: it is never loaded back.
 */
type Settings = {
    status: string | null;
    unlock_available: boolean;
    email_enabled: boolean;
    password_enabled: boolean;
    has_password: boolean;
    viewers_count: number;
    open_password_requests: number;
};

type Viewer = { id: number; email: string | null; method: string; verified_at: string | null; last_viewed_at: string | null; views: number };
type PwRequest = { id: number; email: string; note: string | null; status: string; created_at: string | null };

type ApiError = { response?: { data?: { message?: string; error?: string } } };

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—');

export default function SlugUnlockSettings({ conversationId }: { conversationId: string }) {
    const base = `/ai/conversation/${encodeURIComponent(conversationId)}`;
    const [s, setS] = useState<Settings | null>(null);
    const [password, setPassword] = useState('');
    const [viewers, setViewers] = useState<Viewer[]>([]);
    const [requests, setRequests] = useState<PwRequest[]>([]);
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);

    const load = useCallback(async () => {
        try {
            const [st, lg, rq] = await Promise.all([
                axios.get(`${base}/private-settings`),
                axios.get(`${base}/private-access-logs`),
                axios.get(`${base}/password-requests`),
            ]);
            setS(st.data as Settings);
            setViewers(((lg.data?.data as Viewer[]) || []).filter((v) => v.method !== 'legacy'));
            setRequests((rq.data?.data as PwRequest[]) || []);
        } catch {
            setMsg({ kind: 'err', text: 'Could not load the access settings.' });
        }
    }, [base]);

    useEffect(() => {
        load();
    }, [load]);

    const save = async (body: Record<string, unknown>, ok: string) => {
        setBusy(true);
        setMsg(null);
        try {
            const r = await axios.patch(`${base}/private-settings`, body);
            setS(r.data as Settings);
            setPassword('');
            setMsg({ kind: 'ok', text: ok });
        } catch (e) {
            setMsg({ kind: 'err', text: (e as ApiError)?.response?.data?.message || 'Could not save.' });
        } finally {
            setBusy(false);
        }
    };

    const markRequest = async (id: number, status: 'answered' | 'dismissed') => {
        await axios.patch(`${base}/password-requests/${id}`, { status });
        setRequests((rs) => rs.map((r) => (r.id === id ? { ...r, status } : r)));
    };

    if (!s) {
        return <div className="text-[11px] text-gray-400">Loading access settings…</div>;
    }

    const toggle = 'h-4 w-4 accent-yellow-400';
    const row = 'flex items-start gap-2 text-xs text-gray-300';
    const btn = 'rounded-lg border border-gray-600 px-3 py-1.5 text-[11px] text-gray-200 hover:bg-gray-700/50 disabled:opacity-50';

    return (
        <div className="space-y-3 rounded-xl border border-gray-600 bg-gray-800/40 p-3">
            <h4 className="text-xs font-semibold text-gray-300">Who else can open this private page · 誰可以開啟</h4>
            {!s.unlock_available && (
                <p className="text-[11px] text-yellow-400">
                    Unlock is not switched on for the site yet (SLUG_UNLOCK_V1). Until then only you can open this page.
                </p>
            )}
            <p className="text-[11px] text-gray-400">With both off, only you can open it. 兩者皆關閉時，僅您本人可開啟。</p>

            <label className={row}>
                <input
                    type="checkbox"
                    className={toggle}
                    checked={s.email_enabled}
                    disabled={busy}
                    onChange={(e) => save({ email_enabled: e.target.checked }, e.target.checked ? 'Email unlock is on.' : 'Email unlock is off. Email visitors were signed out.')}
                />
                <span>
                    <b>Anyone who verifies an email</b> (one-time code or link). You see each verified email below.
                    <br />
                    任何人以電子郵件驗證後即可開啟，您可在下方看到驗證過的電子郵件。
                </span>
            </label>

            <label className={row}>
                <input
                    type="checkbox"
                    className={toggle}
                    checked={s.password_enabled}
                    disabled={busy || !s.has_password}
                    onChange={(e) => save({ password_enabled: e.target.checked }, e.target.checked ? 'Password unlock is on.' : 'Password unlock is off. Password visitors were signed out.')}
                />
                <span>
                    <b>Anyone with the password.</b> Visitors can ask you for it with “Request password”; you reply by email.
                    <br />
                    知道密碼者即可開啟；訪客可透過「索取密碼」向您私訊請求。
                </span>
            </label>

            <div className="flex gap-2">
                <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder={s.has_password ? 'New password (6+ characters)' : 'Set a password (6+ characters)'}
                    className="flex-1 rounded-lg border border-gray-600 bg-gray-700/50 px-3 py-1.5 text-xs text-white placeholder-gray-400 outline-none"
                    autoComplete="new-password"
                    disabled={busy}
                />
                <button
                    className={btn}
                    disabled={busy || password.length < 6}
                    onClick={() => save({ password, password_enabled: true }, 'Password saved. Everyone who used the old password was signed out.')}
                >
                    Save password
                </button>
            </div>
            <div className="flex flex-wrap gap-2">
                {s.has_password && (
                    <button className={btn} disabled={busy} onClick={() => save({ clear_password: true }, 'Password removed.')}>
                        Remove password
                    </button>
                )}
                <button className={btn} disabled={busy} onClick={() => save({ sign_out_all: true }, 'All visitors were signed out.')}>
                    Sign out all visitors · 登出所有訪客
                </button>
            </div>

            {msg && <p className={msg.kind === 'ok' ? 'text-[11px] text-green-400' : 'text-[11px] text-red-400'}>{msg.text}</p>}

            <div>
                <h5 className="mb-1 text-[11px] font-semibold text-gray-400">Verified viewers ({viewers.length})</h5>
                {viewers.length === 0 ? (
                    <p className="text-[11px] text-gray-500">Nobody yet.</p>
                ) : (
                    <ul className="max-h-40 space-y-1 overflow-auto text-[11px] text-gray-300">
                        {viewers.map((v) => (
                            <li key={v.id} className="flex justify-between gap-2">
                                <span className="truncate">{v.email || '(password, not signed in)'}</span>
                                <span className="shrink-0 text-gray-500">
                                    {v.method} · {fmt(v.verified_at)} · {v.views} views
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div>
                <h5 className="mb-1 text-[11px] font-semibold text-gray-400">
                    Password requests ({requests.filter((r) => r.status === 'open').length} open)
                </h5>
                {requests.length === 0 ? (
                    <p className="text-[11px] text-gray-500">No requests.</p>
                ) : (
                    <ul className="max-h-40 space-y-1 overflow-auto text-[11px] text-gray-300">
                        {requests.map((r) => (
                            <li key={r.id} className="space-y-0.5">
                                <div className="flex justify-between gap-2">
                                    <a className="truncate underline" href={`mailto:${r.email}`}>
                                        {r.email}
                                    </a>
                                    <span className="shrink-0 text-gray-500">
                                        {r.status} · {fmt(r.created_at)}
                                    </span>
                                </div>
                                {r.note && <div className="text-gray-400">“{r.note}”</div>}
                                {r.status === 'open' && (
                                    <div className="flex gap-2">
                                        <button className={btn} onClick={() => markRequest(r.id, 'answered')}>
                                            Mark answered
                                        </button>
                                        <button className={btn} onClick={() => markRequest(r.id, 'dismissed')}>
                                            Dismiss
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
