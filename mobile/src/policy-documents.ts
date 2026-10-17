import { Capacitor, registerPlugin } from '@capacitor/core';
import { api } from './api';
import { cacheNativeDocument, viewNativeDocument } from './evidence';
import { escape as e } from './utils';
import type { CompanyPolicy } from './types';

const PolicyDocuments = registerPlugin<{ savePdf(options: { path: string; name: string }): Promise<{ saved: boolean }> }>('PolicyDocuments');
const documentUrls = new Set<string>();

export function clearPolicyDocuments() {
  documentUrls.forEach(url => URL.revokeObjectURL(url)); documentUrls.clear();
}

export function bindPolicyDocuments(root: HTMLElement, policy: CompanyPolicy, isCurrent: () => boolean) {
  if (! policy.document) return;
  const status = root.querySelector<HTMLElement>('#policy-document-status')!;
  root.querySelectorAll<HTMLButtonElement>('#policy-read, #policy-download').forEach(button => {
    button.onclick = async () => {
      button.disabled = true; status.textContent = 'Loading policy PDF…';
      let url: string | undefined;
      try {
        // Fetch with the current bearer token/store on every action. Never put
        // credentials in a link, external viewer URL, localStorage or query string.
        const blob = await api.blob(`/company-policy/versions/${policy.id}/pdf`);
        if (! isCurrent()) return;
        if (blob.type !== 'application/pdf') throw new Error('The policy PDF is unavailable. Contact your store owner.');
        const download = button.id === 'policy-download';
        const name = `company-policy-${policy.policy_id ? policy.policy_id + '-' : ''}v${policy.version}.pdf`;
        if (Capacitor.isNativePlatform()) {
          if (download && Capacitor.getPlatform() === 'android') {
            const path = await cacheNativeDocument(blob, -policy.id);
            if (! path || ! isCurrent()) return;
            const result = await PolicyDocuments.savePdf({ path, name });
            if (isCurrent()) status.textContent = result.saved ? 'PDF saved to your chosen location.' : 'Download cancelled. You can try again.';
          } else {
            await viewNativeDocument(blob, -policy.id);
            if (isCurrent()) status.textContent = download ? 'Use the PDF reader’s share or save action to keep a copy.' : 'Return here to confirm after reading the PDF.';
          }
          return;
        }
        url = URL.createObjectURL(blob);
        documentUrls.add(url);
        if (download) {
          const link = document.createElement('a'); link.href = url; link.download = name;
          root.append(link); link.click(); link.remove();
          const downloadUrl = url; setTimeout(() => { URL.revokeObjectURL(downloadUrl); documentUrls.delete(downloadUrl); }, 60000); url = undefined;
          status.textContent = 'PDF download started.';
        } else {
          const dialog = document.createElement('dialog');
          dialog.innerHTML = `<div class="card-head"><h2>${e(policy.title)}</h2><button class="btn small" type="button">Close PDF</button></div><iframe title="Company policy PDF" referrerpolicy="no-referrer"></iframe><p class="field-help">If viewing is unavailable, close this viewer and choose Download PDF.</p>`;
          dialog.querySelector('iframe')!.src = url;
          const viewerUrl = url; url = undefined;
          dialog.querySelector('button')!.onclick = () => dialog.close();
          dialog.addEventListener('close', () => { URL.revokeObjectURL(viewerUrl); documentUrls.delete(viewerUrl); dialog.remove(); });
          root.append(dialog); dialog.showModal();
          status.textContent = '';
        }
      } catch (error) {
        if (isCurrent()) status.textContent = button.id === 'policy-read'
          ? 'Could not open the PDF viewer. Choose Download PDF to save and read it, or check your connection and try again.'
          : (error instanceof Error ? error.message : 'Could not save the PDF. Try again.');
      } finally {
        if (url) { URL.revokeObjectURL(url); documentUrls.delete(url); }
        button.disabled = false;
      }
    };
  });
}
