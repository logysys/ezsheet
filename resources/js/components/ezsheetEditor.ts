// One EzSheet editor for both hosts. The wall srcdoc inlines EZSHEET_EDITOR_SCRIPT as-is;
// React code gets the same object by evaluating that exact source once (no second copy).
import EZSHEET_EDITOR_SCRIPT from './ezsheet-editor.js?raw';

export { EZSHEET_EDITOR_SCRIPT };

export interface EzSheetEditorApi {
  html(o?: any): string;
  CSS: string;
  mount(root: Element, o?: any): any;
  pureFromCore(src: string): { ezParseSheet: (t: string) => any[]; ezSheetIndex: (t: string) => any[]; ezSheetFilter: (ix: any[], q: string, only: string) => any[] } | null;
  DEF: Record<string, string>;
  [k: string]: any;
}

let api: EzSheetEditorApi | null = null;
export function getEzSheetEditor(): EzSheetEditorApi {
  if (api) return api;
  const g: any = typeof window !== 'undefined' ? window : globalThis;
  // eslint-disable-next-line no-new-func
  new Function(EZSHEET_EDITOR_SCRIPT)();
  api = g.EzSheetEditor as EzSheetEditorApi;
  return api;
}
