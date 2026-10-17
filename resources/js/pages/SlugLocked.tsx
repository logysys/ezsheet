import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import type { SharedData } from '@/types';

/**
 * EZ-LOCK-20261017 P2: lock screen for a private slug (HTTP 403, no slug data in props).
 * Rendered by App\Support\SlugAccess::deny() only when the owner turned on an unlock method.
 * props.lock.ref is what the visitor typed (X0000000 handle or the /X/ slug), nothing else.
 */
type LockProps = {
    lock: { ref: string; email: boolean; password: boolean; request_password: boolean };
};

type ApiError = { response?: { status?: number; data?: { message?: string; error?: string } } };

function errorText(e: unknown, fallback: string): string {
    const err = e as ApiError;
    return err?.response?.data?.message || fallback;
}

export default function SlugLocked() {
    const { lock, auth } = usePage<SharedData & LockProps>().props;
    const returnTo = typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/';

    const [email, setEmail] = useState('');
    const [code, setCode] = useState('');
    const [codeSent, setCodeSent] = useState(false);
    const [password, setPassword] = useState('');
    const [note, setNote] = useState('');
    const [showRequest, setShowRequest] = useState(false);
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);

    const reload = () => window.location.assign(returnTo);

    const sendCode = async () => {
        setBusy(true);
        setMsg(null);
        try {
            const r = await axios.post('/slug-unlock/email/send', { ref: lock.ref, email, return_to: returnTo });
            setCodeSent(true);
            setMsg({ kind: 'ok', text: r.data?.message || 'Code sent. Check your inbox.' });
        } catch (e) {
            setMsg({ kind: 'err', text: errorText(e, 'Could not send the code. Please try again.') });
        } finally {
            setBusy(false);
        }
    };

    const verifyCode = async () => {
        setBusy(true);
        setMsg(null);
        try {
            await axios.post('/slug-unlock/email/verify', { ref: lock.ref, email, code });
            reload();
        } catch (e) {
            setMsg({ kind: 'err', text: errorText(e, 'Wrong or expired code.') });
            setBusy(false);
        }
    };

    const tryPassword = async () => {
        setBusy(true);
        setMsg(null);
        try {
            await axios.post('/slug-unlock/password', { ref: lock.ref, password });
            reload();
        } catch (e) {
            setMsg({ kind: 'err', text: errorText(e, 'Wrong password.') });
            setBusy(false);
        }
    };

    const requestPassword = async () => {
        setBusy(true);
        setMsg(null);
        try {
            const r = await axios.post('/slug-unlock/request-password', { ref: lock.ref, note });
            setShowRequest(false);
            setMsg({ kind: 'ok', text: r.data?.message || 'Request sent.' });
        } catch (e) {
            setMsg({ kind: 'err', text: errorText(e, 'Could not send the request.') });
        } finally {
            setBusy(false);
        }
    };

    const input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/30';
    const button = 'w-full rounded-full bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
    const link = 'text-sm font-medium text-teal-700 underline disabled:opacity-50';

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 p-5 text-gray-800">
            <Head>
                <title>Private page · ez.wiki</title>
                <meta name="robots" content="noindex, nofollow, noarchive" />
            </Head>
            <div className="w-full max-w-md space-y-5 rounded-xl bg-white p-7 shadow-lg">
                <div className="space-y-1 text-center">
                    <div className="text-3xl" aria-hidden>
                        🔒
                    </div>
                    <h1 className="text-xl font-bold">This page is private</h1>
                    <p className="text-sm text-gray-500">此頁為私人頁面</p>
                </div>

                {lock.email && (
                    <section className="space-y-2" aria-label="Open with email">
                        <h2 className="text-sm font-semibold">Open with your email · 以電子郵件開啟</h2>
                        <p className="text-xs text-gray-500">
                            We email you a one-time code and link. The page owner will see the email address you use.
                            <br />
                            我們會寄送一次性驗證碼與連結；頁面擁有者會看到您使用的電子郵件地址。
                        </p>
                        <input
                            type="email"
                            className={input}
                            placeholder="you@example.com"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            disabled={busy || codeSent}
                            autoComplete="email"
                        />
                        {!codeSent ? (
                            <button className={button} onClick={sendCode} disabled={busy || !email}>
                                Send code · 寄送驗證碼
                            </button>
                        ) : (
                            <>
                                <input
                                    inputMode="numeric"
                                    maxLength={6}
                                    className={input + ' tracking-[0.4em]'}
                                    placeholder="123456"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                                    disabled={busy}
                                    autoComplete="one-time-code"
                                />
                                <button className={button} onClick={verifyCode} disabled={busy || code.length !== 6}>
                                    Open page · 開啟頁面
                                </button>
                                <button className={link} onClick={() => setCodeSent(false)} disabled={busy}>
                                    Use another email / send again
                                </button>
                            </>
                        )}
                    </section>
                )}

                {lock.email && lock.password && <hr className="border-gray-200" />}

                {lock.password && (
                    <section className="space-y-2" aria-label="Open with password">
                        <h2 className="text-sm font-semibold">Open with the password · 以密碼開啟</h2>
                        <input
                            type="password"
                            className={input}
                            placeholder="Password · 密碼"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && password && tryPassword()}
                            disabled={busy}
                            autoComplete="off"
                        />
                        <button className={button} onClick={tryPassword} disabled={busy || !password}>
                            Open page · 開啟頁面
                        </button>
                        {lock.request_password &&
                            (auth?.user ? (
                                showRequest ? (
                                    <div className="space-y-2">
                                        <textarea
                                            className={input}
                                            rows={3}
                                            maxLength={500}
                                            placeholder="Message to the owner (optional) · 給擁有者的訊息（選填）"
                                            value={note}
                                            onChange={(e) => setNote(e.target.value)}
                                        />
                                        <button className={button} onClick={requestPassword} disabled={busy}>
                                            Send request · 送出請求
                                        </button>
                                    </div>
                                ) : (
                                    <button className={link} onClick={() => setShowRequest(true)} disabled={busy}>
                                        Request password from the owner · 向擁有者索取密碼
                                    </button>
                                )
                            ) : (
                                <a className={link} href={'/login?redirect=' + encodeURIComponent(returnTo)}>
                                    Sign in to request the password · 登入以索取密碼
                                </a>
                            ))}
                    </section>
                )}

                {msg && (
                    <p role="status" className={msg.kind === 'ok' ? 'text-sm text-teal-700' : 'text-sm text-red-600'}>
                        {msg.text}
                    </p>
                )}

                <p className="text-center text-xs text-gray-400">
                    <a href="/">ez.wiki</a>
                </p>
            </div>
        </div>
    );
}
