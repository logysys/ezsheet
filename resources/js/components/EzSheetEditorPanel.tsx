import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { getEzSheetEditor } from './ezsheetEditor';

/* React host for the shared EzSheet editor (ezsheet-editor.js), the same editor as the wall
   EzBar panel: hint line, EzSheet / Images & 3D tabs, filter with === / ### chips, custom scroll
   bar, item count, Cancel / Push. The text is owned by the parent (value / onChange). */
interface Props {
  value: string;
  onChange: (v: string) => void;
  onPush: () => void;
  onCancel: () => void;
  pushLabel: string;
  pushDisabled?: boolean;
  placeholder?: string;
  hint?: string;
  pics?: React.ReactNode; // content of the Images & 3D tab
  media?: React.ReactNode; // content of the tabMedia tab
  top?: React.ReactNode; // shown right under the hint line
}

const cardCount = (n: number) => (n === 1 ? '1 card' : `${n} cards`);

export default function EzSheetEditorPanel({ value, onChange, onPush, onCancel, pushLabel, pushDisabled, placeholder, hint, pics, media, top }: Props) {
  const rootRef = useRef<HTMLDivElement>(null);
  const apiRef = useRef<any>(null);
  const cbRef = useRef({ onChange, onPush, onCancel });
  cbRef.current = { onChange, onPush, onCancel };
  const [slots, setSlots] = useState<{ pics: HTMLElement | null; top: HTMLElement | null; media: HTMLElement | null }>({ pics: null, top: null, media: null });

  const handleAddCards = (raws: string[], zone: string) => {
    if (!raws.length) return;
    const E = getEzSheetEditor();
    let cur = value;
    raws.forEach((r) => {
      const sep = zone === 'a' ? E.ZSEP : (zone === 'c' ? E.CSEP : E.SEP);
      cur = cur.trim() ? `${cur.trim()}\n${sep}\n${r}` : r;
    });
    cbRef.current.onChange(cur);
  };

  useEffect(() => {
    const E = getEzSheetEditor();
    const root = rootRef.current;
    if (!root) return;
    if (!document.getElementById('ezs-editor-css')) {
      const st = document.createElement('style');
      st.id = 'ezs-editor-css';
      st.textContent = E.CSS;
      document.head.appendChild(st);
    }
    root.innerHTML = E.html({ labels: { push: pushLabel, hint }, placeholder, top: '<div data-ezs="top"></div>' });
    let parse: ((t: string) => any[]) | null = null;
    const api = E.mount(root, {
      onInput: (v: string) => cbRef.current.onChange(v),
      onPush: () => cbRef.current.onPush(),
      onCancel: () => cbRef.current.onCancel(),
      escCancel: false, // Esc must not wipe the homepage text
      count: (t: string) => (parse ? cardCount(parse(t).length) : ''),
    });
    apiRef.current = api;
    api.setValue(value);
    /* Tab indents (homepage behaviour kept) */
    const ta = api.ta as HTMLTextAreaElement;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Tab' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
      e.preventDefault();
      ta.setRangeText('  ', ta.selectionStart, ta.selectionEnd, 'end');
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    };
    ta.addEventListener('keydown', onKey);
    setSlots({
      pics: root.querySelector('[data-ezs="panePics"]'),
      top: root.querySelector('[data-ezs="top"]'),
      media: root.querySelector('[data-ezs="paneMedia"]'),
    });
    /* the filter and the count use wall-core's own sheet parser (loaded on demand) */
    let alive = true;
    import('./wall-core.js?raw').then((m) => {
      if (!alive) return;
      const P = E.pureFromCore(m.default);
      if (P) { parse = P.ezParseSheet; api.setParser(P.ezSheetIndex, P.ezSheetFilter); api.count(); }
    }).catch(() => {});
    return () => { alive = false; ta.removeEventListener('keydown', onKey); api.destroy(); root.innerHTML = ''; apiRef.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { if (apiRef.current) apiRef.current.setValue(value); }, [value]);
  useEffect(() => {
    const b = rootRef.current?.querySelector('[data-ezs="push"]') as HTMLButtonElement | null;
    if (b) { b.textContent = pushLabel; b.disabled = !!pushDisabled; }
  }, [pushLabel, pushDisabled, slots]);

  return (
    <>
      <div className="ezs" ref={rootRef} data-testid="ezsheet-editor" />
      {slots.pics && pics ? createPortal(pics, slots.pics) : null}
      {slots.media ? createPortal(media || <DefaultMediaUploadPane onAddCards={handleAddCards} />, slots.media) : null}
      {slots.top && top ? createPortal(top, slots.top) : null}
    </>
  );
}

interface MediaItem {
  id: string;
  file: File;
  name: string;
  ext: string;
  kind: string;
  sizeFormatted: string;
  status: 'uploading' | 'finished' | 'error';
  progress: number;
  error?: string;
  xhr?: XMLHttpRequest;
  cardBlock?: string;
}

function DefaultMediaUploadPane({ onAddCards }: { onAddCards: (raws: string[], zone: string) => void }) {
  const [items, setItems] = useState<MediaItem[]>([]);
  const [showSupported, setShowSupported] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const formatSize = (bytes: number) => {
    if (!bytes || bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
  };

  const detectKind = (name: string, type: string) => {
    const ext = (name.split('.').pop() || '').toLowerCase();
    const t = (type || '').toLowerCase();
    if (t.startsWith('image/') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'avif', 'svg'].includes(ext) || t === 'image/svg+xml') return 'image';
    if (t === 'application/pdf' || ext === 'pdf') return 'pdf';
    if (t.startsWith('video/') || ['mp4', 'webm', 'ogg', 'mov', 'm4v', 'mkv'].includes(ext)) return 'video';
    if (t.startsWith('audio/') || ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(ext)) return 'audio';
    if (t === 'text/html' || ext === 'html' || ext === 'htm') return 'html';
    return 'file';
  };

  const startUpload = (item: MediaItem) => {
    if (item.file.size > 100 * 1024 * 1024) {
      setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'error', error: 'Exceeds 100MB limit', progress: 0 } : it)));
      return;
    }

    // HTML: inlined as a sandboxed card, not stored on the server
    if (item.kind === 'html') {
      setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'uploading', progress: 50 } : it)));
      const reader = new FileReader();
      reader.onload = (e) => {
        const raw = String(e.target?.result || '');
        const escaped = raw.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
        const block = `<div class="sandboxed-html-card" style="border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#fff;width:100%;display:flex;flex-direction:column;font-family:sans-serif">\n  <div style="background:#1e293b;color:#f8fafc;padding:7px 12px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:space-between">\n    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%">🌐 ${item.name}</span>\n    <span style="font-size:10px;background:#334155;color:#cbd5e1;padding:2px 6px;border-radius:4px">Sandboxed HTML</span>\n  </div>\n  <iframe srcdoc="${escaped}" sandbox="allow-scripts" style="width:100%;min-height:280px;height:320px;border:none;background:#fff" title="${item.name}"></iframe>\n</div>`;
        setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'finished', progress: 100, cardBlock: block } : it)));
        onAddCards([block], 'b');
      };
      reader.onerror = () => {
        setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'error', error: 'Failed to read HTML file' } : it)));
      };
      reader.readAsText(item.file);
      return;
    }

    const finishWithUrl = (url: string) => {
      let block = '';
      if (item.kind === 'image') {
        block = `<img src="${url}" alt="${item.name}" style="max-width:100%;height:auto;display:block;border-radius:8px;margin:0 auto" />`;
      } else if (item.kind === 'video') {
        block = `<video src="${url}" controls playsinline style="max-width:100%;width:100%;display:block;border-radius:8px;background:#000"></video>`;
      } else if (item.kind === 'audio') {
        block = `<div style="background:linear-gradient(135deg,#0f172a,#1e1b4b);padding:14px;border-radius:10px;color:#fff;display:flex;flex-direction:column;gap:8px">\n  <div style="font-size:12px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎵 ${item.name}</div>\n  <audio src="${url}" controls style="width:100%"></audio>\n</div>`;
      } else if (item.kind === 'pdf') {
        block = `<div class="pdf-link-card" style="border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);font-family:sans-serif">\n  <div style="background:#1e293b;color:#f8fafc;padding:10px 14px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:space-between">\n    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%">📄 ${item.name}</span>\n    <a href="${url}" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;background:#334155;border-radius:4px;padding:3px 10px;text-decoration:none;font-size:11px;font-weight:700">Open ↗</a>\n  </div>\n  <iframe src="${url}#toolbar=0" style="width:100%;height:320px;border:none;background:#fff" title="${item.name}"></iframe>\n</div>`;
      } else {
        block = `<div style="border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between"><span>📁 ${item.name}</span><a href="${url}" download="${item.name}" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:700">Download ⬇</a></div>`;
      }
      setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'finished', progress: 100, cardBlock: block, xhr: undefined } : it)));
      if (block) onAddCards([block], 'b');
    };

    const fallbackLocal = () => {
      try {
        const r = new FileReader();
        r.onload = (ev) => finishWithUrl(String(ev.target?.result || ''));
        r.onerror = () => {
          try { finishWithUrl(URL.createObjectURL(item.file)); } catch {
            setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'error', error: 'Upload failed', xhr: undefined } : it)));
          }
        };
        r.readAsDataURL(item.file);
      } catch {
        try { finishWithUrl(URL.createObjectURL(item.file)); } catch {
          setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'error', error: 'Upload failed', xhr: undefined } : it)));
        }
      }
    };

    // Server upload for image, video, audio, pdf, file
    const xhr = new XMLHttpRequest();
    const wallId = (typeof window !== 'undefined' && (window as any).WALL_ID) || 'ezsheet';

    setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, status: 'uploading', progress: 0, error: undefined, xhr } : it)));

    const fd = new FormData();
    fd.append('file', item.file);
    fd.append('wall_id', wallId);

    xhr.open('POST', `/api/wall/${encodeURIComponent(wallId)}/media`, true);
    xhr.withCredentials = true;

    try {
      const tok = localStorage.getItem('wall_owner');
      if (tok) xhr.setRequestHeader('x-wall-owner', tok);
    } catch {}
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = (ev) => {
      if (ev.lengthComputable) {
        const pct = Math.min(99, Math.round((ev.loaded / ev.total) * 100));
        setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, progress: pct } : it)));
      }
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const res = JSON.parse(xhr.responseText);
          if (res.success && res.url) {
            finishWithUrl(res.url);
            return;
          }
        } catch {}
      }
      fallbackLocal();
    };

    xhr.onerror = () => {
      fallbackLocal();
    };

    xhr.send(fd);
  };

  const handleFiles = (files: FileList | null) => {
    if (!files || !files.length) return;
    const newItems: MediaItem[] = [];
    Array.from(files).forEach((file) => {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      const kind = detectKind(file.name, file.type);
      const item: MediaItem = {
        id: 'mf_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
        file,
        name: file.name,
        ext,
        kind,
        sizeFormatted: formatSize(file.size),
        status: 'uploading',
        progress: 0,
      };
      newItems.push(item);
    });

    setItems((prev) => [...prev, ...newItems]);
    newItems.forEach((it) => startUpload(it));
  };

  const handleRetry = (id: string) => {
    const it = items.find((x) => x.id === id);
    if (it) startUpload(it);
  };

  const handleRemove = (id: string) => {
    const it = items.find((x) => x.id === id);
    if (it?.xhr) {
      try { it.xhr.abort(); } catch {}
    }
    setItems((prev) => prev.filter((x) => x.id !== id));
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', padding: '4px 0 8px' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          <span style={{ fontWeight: 700, fontSize: '13px', color: '#0f172a' }}>Upload Media (Straight to Content)</span>
          <button type="button" onClick={() => setShowSupported(!showSupported)} style={{ background: 'none', border: 'none', fontSize: '11.5px', color: '#2563eb', textDecoration: 'underline', cursor: 'pointer', padding: 0, marginLeft: '4px' }}>
            Supported types
          </button>
        </div>
        <span style={{ fontSize: '11.5px', color: '#64748b', fontWeight: 500 }}>Up to 100MB per file</span>
      </div>

      {showSupported && (
        <div style={{ background: '#f8fafc', border: '1.5px solid #cbd5e1', borderRadius: '12px', padding: '12px 14px', fontSize: '11.5px', lineHeight: 1.6, color: '#334155' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px', fontWeight: 700, color: '#0f172a' }}>
            <span>Supported Formats & Behavior:</span>
            <button type="button" onClick={() => setShowSupported(false)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontWeight: 800, fontSize: '14px', color: '#64748b' }}>✕</button>
          </div>
          <ul style={{ margin: 0, paddingLeft: '18px' }}>
            <li><b>Image</b>: Uploaded to server & added as &lt;img&gt;</li>
            <li><b>Video</b>: Uploaded to server & added as &lt;video controls&gt;</li>
            <li><b>Audio</b>: Uploaded to server & added as &lt;audio controls&gt;</li>
            <li><b>PDF</b>: Uploaded to server & added as an &quot;Open PDF ↗&quot; link card</li>
            <li><b>HTML</b>: Inlined as a sandboxed card (not stored on server)</li>
          </ul>
          <div style={{ marginTop: '6px', color: '#b91c1c', fontSize: '11px' }}>
            Note: SVG, Markdown, ZIP archives, Office docs, and executables/scripts are rejected.
          </div>
        </div>
      )}

      <div
        onClick={() => fileInputRef.current?.click()}
        onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); }}
        onDrop={(e) => { e.preventDefault(); e.stopPropagation(); handleFiles(e.dataTransfer.files); }}
        style={{ border: '2.5px dashed #0284c7', borderRadius: '14px', padding: '28px 20px', textAlign: 'center', cursor: 'pointer', background: '#f0f9ff', transition: 'all .18s ease' }}
      >
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#0284c7" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" style={{ margin: '0 auto 10px', display: 'block' }}>
          <rect x="3" y="3" width="18" height="18" rx="3" ry="3" />
          <circle cx="8.5" cy="8.5" r="1.5" />
          <polyline points="21 15 16 10 5 21" />
        </svg>
        <div style={{ fontSize: '13.5px', marginBottom: '6px' }}>
          <span style={{ color: '#0284c7', fontWeight: 700 }}>Click to choose multiple files</span>
          <span style={{ color: '#334155', fontWeight: 600 }}> or drag & drop</span>
        </div>
        <div style={{ fontSize: '11.5px', color: '#475569', marginBottom: '4px', fontWeight: 500 }}>
          Images • Video • Audio • PDF • HTML
        </div>
        <div style={{ fontSize: '11px', color: '#64748b' }}>Each finished upload is added straight to Content in the sheet; Push creates cards.</div>
        <input ref={fileInputRef} type="file" accept="image/*,video/*,audio/*,.pdf,.html,.htm,application/pdf,text/html" multiple hidden onChange={(e) => { handleFiles(e.target.files); e.target.value = ''; }} />
      </div>

      {items.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxHeight: '280px', overflowY: 'auto', padding: '2px' }}>
          {items.map((item) => {
            const isErr = item.status === 'error';
            const isDone = item.status === 'finished';
            const statusColor = isDone ? '#16a34a' : isErr ? '#dc2626' : '#0284c7';
            const statusText = isDone ? '✓ Added to Content in sheet' : isErr ? `✕ ${item.error || 'Failed'}` : `Uploading ${item.progress}%`;
            const progColor = isDone ? '#16a34a' : isErr ? '#ef4444' : '#0284c7';

            return (
              <div key={item.id} style={{ display: 'flex', flexDirection: 'column', gap: '6px', background: '#fff', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '8px 12px' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', minWidth: 0, flex: 1 }}>
                    <span style={{ fontSize: '9.5px', fontWeight: 900, textTransform: 'uppercase', padding: '2px 7px', borderRadius: '999px', background: item.kind === 'pdf' ? '#fee2e2' : item.kind === 'video' ? '#ede9fe' : item.kind === 'audio' ? '#fef3c7' : item.kind === 'html' ? '#dcfce7' : '#e0f2fe', color: item.kind === 'pdf' ? '#b91c1c' : item.kind === 'video' ? '#6d28d9' : item.kind === 'audio' ? '#b45309' : item.kind === 'html' ? '#15803d' : '#0369a1' }}>
                      {item.kind}
                    </span>
                    <span style={{ fontSize: '12px', fontWeight: 700, color: '#0f172a', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={item.name}>
                      {item.name}
                    </span>
                    <span style={{ fontSize: '11px', color: '#64748b', fontWeight: 600, flexShrink: 0 }}>({item.sizeFormatted})</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexShrink: 0 }}>
                    {isErr && (
                      <button type="button" onClick={() => handleRetry(item.id)} style={{ padding: '2px 8px', fontSize: '11px', color: '#2563eb', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: '4px', cursor: 'pointer', fontWeight: 600 }}>
                        Retry
                      </button>
                    )}
                    <button type="button" onClick={() => handleRemove(item.id)} style={{ padding: '2px 6px', fontSize: '11px', color: '#dc2626', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: '4px', cursor: 'pointer', fontWeight: 600 }} title="Remove">
                      Remove
                    </button>
                  </div>
                </div>
                <div style={{ height: '6px', background: '#f1f5f9', borderRadius: '3px', overflow: 'hidden', position: 'relative' }}>
                  <div style={{ height: '100%', width: `${item.progress}%`, background: progColor, transition: 'width 0.2s' }} />
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '11px' }}>
                  <span style={{ color: statusColor, fontWeight: 600 }}>{statusText}</span>
                  <span style={{ color: '#64748b', fontWeight: 600 }}>{item.progress}%</span>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
