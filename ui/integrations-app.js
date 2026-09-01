export const TOKEN_SCOPES = ['forms:read', 'forms:write', 'forms:publish', 'submissions:read', 'tokens:read', 'tokens:write', 'webhooks:read', 'webhooks:write'];

export function tokenRequest(name, scopes, days) {
  if (!name.trim() || name.trim().length > 100 || !scopes.length || new Set(scopes).size !== scopes.length || scopes.some(scope => !TOKEN_SCOPES.includes(scope))) throw new Error('Enter a name and select the scopes this integration needs.');
  const lifetime = Number(days);
  if (!Number.isInteger(lifetime) || lifetime < 1 || lifetime > 365) throw new Error('Choose a lifetime from 1 to 365 days.');
  return { name: name.trim(), scopes, expiresInSeconds: lifetime * 86400 };
}

export function initIntegrations({ context, verifyWorkspace }) {
  const $ = id => document.getElementById(id);
  let generation = 0, controller = null, busy = false, mutationPending = false, uncertain = false;
  let tokensOpen = false, webhook = null, webhookLoaded = false, revealed = '', revealTimer;
  const urls = new Set();
  const allowed = () => ['owner', 'admin'].includes(context().role) && !context().sessionExpired;
  const message = value => { $('integration-message').textContent = value; };
  const tokenPath = '/api/control-plane/service-tokens';
  const formPath = () => `/api/control-plane/forms/${encodeURIComponent(context().form.id)}`;
  function clearReveal() {
    revealed = ''; $('issued-token').value = ''; $('token-reveal').hidden = true; clearTimeout(revealTimer);
    for (const url of urls) URL.revokeObjectURL(url); urls.clear();
  }
  function clearWebhook() {
    webhook = null; webhookLoaded = false;
    $('webhook-state').textContent = 'Load this form’s webhook to see its current state.';
    $('pause-webhook').textContent = 'Pause future deliveries';
  }
  function reset() {
    uncertain ||= mutationPending;
    generation++; controller?.abort(); controller = null; busy = false; mutationPending = false;
    clearReveal(); clearWebhook();
    $('token-list').replaceChildren(); $('webhook-deliveries').replaceChildren();
    $('webhook-settings').reset(); $('integration-error').hidden = true; $('integration-error').textContent = ''; message('');
    controls();
  }
  function controls() {
    const locked = busy || context().busy || !allowed();
    $('manage-tokens').disabled = locked;
    $('tokens-panel').hidden = !tokensOpen || !allowed();
    $('token-fields').disabled = locked || uncertain;
    $('reload-tokens').disabled = locked;
    $('webhook-panel').hidden = !context().form;
    $('webhook-access').hidden = allowed();
    $('webhook-fields').disabled = locked || uncertain;
    $('load-webhook').disabled = locked;
    for (const id of ['pause-webhook', 'rotate-webhook', 'delete-webhook']) $(id).disabled = locked || uncertain || !webhook;
    $('load-deliveries').disabled = locked || !context().form;
    $('integration-uncertain').hidden = !uncertain;
    $('acknowledge-integration').disabled = locked;
    for (const id of ['token-list', 'webhook-deliveries']) for (const button of $(id).querySelectorAll('button')) button.disabled = locked || (id === 'webhook-deliveries' && uncertain);
    if (!allowed()) { clearReveal(); clearWebhook(); $('token-list').replaceChildren(); $('webhook-deliveries').replaceChildren(); $('webhook-settings').reset(); }
  }
  async function request(path, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    mutationPending = method !== 'GET';
    let response, payload;
    try {
      response = await fetch(path, { method, headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20_000)]), body: body === undefined ? undefined : JSON.stringify(body) });
      if (response.status === 204) { mutationPending = false; return null; }
      const blob = await response.blob();
      if (blob.size > 262144) throw new Error('Response too large');
      payload = JSON.parse(await blob.text());
    } catch {
      uncertain ||= method !== 'GET';
      throw new Error(method === 'GET' ? 'The response could not be loaded. Reload metadata.' : 'The change may have succeeded. Reload metadata and reconcile before retrying.');
    }
    mutationPending = false;
    if (!response.ok) {
      if (response.status === 401) context().sessionExpired = true;
      const noCommitMessages = {
        data_plane_authentication_failed: method === 'GET' ? 'Data-plane authentication is unavailable. Reload metadata later.' : 'No change was committed because data-plane authentication is unavailable.',
        management_audit_unavailable: 'No change was committed because its audit could not be stored.',
        webhooks_disabled: 'No change was committed because webhook management is not available.',
        service_unavailable: 'No change was committed because service-token management is not available.',
      };
      const noCommitStatuses = { data_plane_authentication_failed: 502, management_audit_unavailable: 503, webhooks_disabled: 503, service_unavailable: 503 };
      const noCommit = noCommitStatuses[payload?.error?.code] === response.status;
      uncertain ||= method !== 'GET' && response.status >= 500 && !noCommit;
      const codeMessages = { data_plane_access_denied: 'The data plane denied this integration operation.' };
      const messages = { 400: 'Check the integration settings and selected scopes.', 401: 'Sign in again.', 403: 'Your current workspace role cannot manage integrations.',
        404: 'The integration resource was not found in this workspace.', 409: 'The integration changed concurrently. Reload metadata before retrying.',
        412: 'The integration precondition is stale. Reload metadata before retrying.', 413: 'The settings exceed the supported size.',
        422: 'Check the destination, secret length, name, expiry and scopes.', 429: 'Too many requests. Wait before retrying.' };
      const error = new Error(noCommit ? noCommitMessages[payload.error.code] : (codeMessages[payload?.error?.code] ?? messages[response.status] ?? 'The outcome may be uncertain. Reload metadata and reconcile before retrying.'));
      error.status = response.status; throw error;
    }
    return payload;
  }
  async function act(work) {
    if (busy || context().busy || !allowed()) return;
    const current = ++generation;
    controller?.abort(); controller = new AbortController(); busy = true;
    clearReveal(); $('integration-error').hidden = true; message(''); controls();
    try {
      await verifyWorkspace();
      if (!allowed()) throw new Error('Your current workspace role cannot manage integrations.');
      if (current !== generation) return;
      await work(() => current === generation && !document.hidden);
    } catch (error) {
      if (current !== generation) return;
      clearReveal(); $('integration-error').textContent = error.message; $('integration-error').hidden = false; $('integration-error').focus();
    } finally {
      if (current === generation) { busy = false; mutationPending = false; controller = null; controls(); }
    }
  }
  function label(parent, text) { const paragraph = document.createElement('p'); paragraph.textContent = text; parent.append(paragraph); }
  async function loadTokens(active) {
    const result = await request(tokenPath);
    if (!active()) return;
    if (!Array.isArray(result.data) || result.data.length > 100) throw new Error('Token metadata is unavailable.');
    $('token-list').replaceChildren();
    for (const token of result.data) {
      if (!/^[A-Za-z0-9_-]{16}$/.test(token.id) || token.organizationId !== context().organization || !Array.isArray(token.scopes)) throw new Error('Token metadata does not match this workspace.');
      const row = document.createElement('article'); row.className = 'integration-record';
      label(row, `${token.name} · ${token.status}`); label(row, `ID: ${token.id}`); label(row, `Scopes: ${token.scopes.join(', ')}`);
      label(row, `Created: ${token.createdAt} · Expires: ${token.expiresAt}`);
      label(row, `Last observed use: ${token.lastUsedAt ?? 'Not recorded'}`);
      if (token.status !== 'revoked') {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = `Revoke ${token.name}`;
        button.onclick = () => { if (window.confirm(`Revoke “${token.name}” (${token.id})? Its integration will lose access.`)) act(async active => {
          await request(`${tokenPath}/${encodeURIComponent(token.id)}`, { method: 'DELETE' });
          if (!active()) return; await loadTokens(active); message('Token revoked. It cannot authenticate new API requests.');
        }); }; row.append(button);
      }
      $('token-list').append(row);
    }
    if (!result.data.length) label($('token-list'), 'No token metadata returned.');
  }
  async function loadWebhook(active) {
    let result;
    try { result = await request(formPath() + '/webhook'); }
    catch (error) { if (error.status !== 404) throw error; result = { data: null }; }
    if (!active()) return;
    webhook = result.data; webhookLoaded = true;
    if (webhook && (webhook.formId !== context().form.id || typeof webhook.enabled !== 'boolean')) throw new Error('Webhook metadata does not match this form.');
    $('webhook-state').textContent = webhook ? `${webhook.enabled ? 'Enabled for future submissions' : 'Paused for future submissions'} · ${webhook.origin} · Updated ${webhook.updatedAt}` : 'No webhook endpoint is configured for this form.';
    $('pause-webhook').textContent = webhook?.enabled ? 'Pause future deliveries' : 'Resume future deliveries';
  }
  async function loadDeliveries(active) {
    const result = await request(formPath() + '/deliveries');
    if (!active()) return;
    if (!Array.isArray(result.data) || result.data.length > 100) throw new Error('Delivery metadata is unavailable.');
    $('webhook-deliveries').replaceChildren();
    for (const delivery of result.data) {
      const row = document.createElement('article'); row.className = 'integration-record';
      label(row, `${delivery.id} · ${delivery.status} · ${delivery.attemptCount} attempts`);
      label(row, `HTTP: ${delivery.lastHttpStatus ?? 'Not recorded'} · Result: ${delivery.lastErrorCategory || 'Not recorded'} · Next attempt: ${delivery.nextAttemptAt}`);
      if (delivery.status === 'dead_letter' && /^[0-9a-f-]{36}$/i.test(delivery.id)) {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = `Replay ${delivery.id}`;
        button.onclick = () => { if (window.confirm('Replay this dead letter using its ORIGINAL destination and signing secret? Confirm the receiver still accepts that key and deduplicates delivery IDs.')) act(async active => {
          await request(formPath() + `/deliveries/${encodeURIComponent(delivery.id)}/replay`, { method: 'POST' });
          if (!active()) return; await loadDeliveries(active); message('Delivery requeued with its original snapshot. This does not prove receiver acceptance.');
        }); }; row.append(button);
      }
      $('webhook-deliveries').append(row);
    }
    if (!result.data.length) label($('webhook-deliveries'), 'No deliveries in this recent window.');
  }
  $('manage-tokens').onclick = () => { tokensOpen = true; controls(); $('tokens-panel').scrollIntoView({ block: 'start' }); act(loadTokens); };
  $('reload-tokens').onclick = () => act(loadTokens);
  $('token-create').onsubmit = event => {
    event.preventDefault(); if (uncertain || !$('token-create').reportValidity()) return;
    let body;
    try { body = tokenRequest($('token-name').value, [...$('token-scopes').querySelectorAll('input:checked')].map(input => input.value), $('token-days').value); }
    catch (error) { $('integration-error').textContent = error.message; $('integration-error').hidden = false; return; }
    act(async active => {
      const result = await request(tokenPath, { method: 'POST', body });
      if (!active()) return;
      if (!/^gfst_[A-Za-z0-9_-]{43}$/.test(result?.data?.token ?? '')) { uncertain = true; throw new Error('The issued credential was not received intact. Reload metadata and revoke the unclaimed token.'); }
      // Reveal the successful one-time response without depending on another request.
      revealed = result.data.token; $('issued-token').value = revealed; $('token-reveal').hidden = false;
      $('token-create').reset(); $('token-reveal').scrollIntoView({ block: 'nearest' });
      revealTimer = setTimeout(clearReveal, 120000); message('Token created. Copy or download it now; it cannot be recovered later.');
    });
  };
  $('dismiss-token').onclick = clearReveal;
  $('copy-token').onclick = async () => { if (!revealed) return; try { await navigator.clipboard.writeText(revealed); message('Token copied. Clipboard managers may retain it; store it in your integration’s secret manager.'); } catch { message('Clipboard unavailable. Select and copy the token before dismissing it.'); } };
  $('download-token').onclick = () => { if (!revealed) return; const url = URL.createObjectURL(new Blob([revealed + '\n'], { type: 'text/plain' })); urls.add(url); const link = document.createElement('a'); link.href = url; link.download = 'goformx-service-token.txt'; link.click(); setTimeout(() => { URL.revokeObjectURL(url); urls.delete(url); }, 1000); message('Downloaded a secret file. Move it into secret custody and remove unneeded copies.'); };
  $('load-webhook').onclick = () => act(loadWebhook);
  $('load-deliveries').onclick = () => act(loadDeliveries);
  $('generate-webhook-secret').onclick = () => { $('webhook-secret').value = [...crypto.getRandomValues(new Uint8Array(32))].map(byte => byte.toString(16).padStart(2, '0')).join(''); $('receiver-ready').checked = false; message('New signing secret generated locally. Copy it to your receiver before saving; retain the old key for outstanding deliveries.'); };
  $('copy-webhook-secret').onclick = async () => { try { if (!$('webhook-secret').value) return; await navigator.clipboard.writeText($('webhook-secret').value); message('Signing secret copied. Install it at your receiver before saving.'); } catch { message('Clipboard unavailable. Re-enter a receiver-managed secret instead.'); } };
  const secretReady = () => $('receiver-ready').checked && [...$('webhook-secret').value].length >= 32 && [...$('webhook-secret').value].length <= 256;
  $('webhook-settings').onsubmit = event => {
    event.preventDefault(); if (uncertain || !$('webhook-settings').reportValidity() || !secretReady()) return;
    if (!webhookLoaded) { message('Load the current webhook before creating or replacing it.'); return; }
    if (webhook && !window.confirm('Replace the complete endpoint configuration? Supply all headers and the signing secret; omitted headers will be removed. Accepted deliveries retain their old snapshot.')) return;
    let headers;
    try { headers = JSON.parse($('webhook-headers').value || '{}'); if (!headers || Array.isArray(headers) || typeof headers !== 'object') throw new Error(); }
    catch { message('Custom headers must be a JSON object.'); return; }
    const body = { url: $('webhook-url').value, headers, signingSecret: $('webhook-secret').value, enabled: $('webhook-enabled').checked };
    act(async active => { $('webhook-secret').value = ''; $('webhook-headers').value = ''; $('receiver-ready').checked = false;
      await request(formPath() + '/webhook', { method: 'PUT', body }); if (!active()) return; await loadWebhook(active); message('Endpoint configured. Destination paths, headers and signing secrets cannot be read back.'); });
  };
  $('pause-webhook').onclick = () => { if (!webhook || uncertain || !window.confirm(webhook.enabled ? 'Pause future enqueueing? Accepted deliveries can still be dispatched.' : 'Resume future enqueueing? Submissions accepted while paused are not backfilled.')) return;
    const enabled = !webhook.enabled; act(async active => { await request(formPath() + '/webhook', { method: 'PATCH', body: { enabled } }); if (!active()) return; await loadWebhook(active); message('Future delivery setting updated. Accepted deliveries are unchanged.'); }); };
  $('rotate-webhook').onclick = () => { if (!webhook || uncertain) return; if (!secretReady()) { message('Enter a 32–256 character secret and confirm the receiver is ready.'); return; }
    if (!window.confirm('Rotate the signing secret for future deliveries? The receiver must keep the old key for outstanding deliveries and dead-letter replay.')) return;
    const signingSecret = $('webhook-secret').value; act(async active => { $('webhook-secret').value = ''; $('receiver-ready').checked = false; await request(formPath() + '/webhook', { method: 'PATCH', body: { signingSecret } }); if (!active()) return; await loadWebhook(active); message('Signing secret rotated for future deliveries. Existing snapshots still use the old secret.'); }); };
  $('delete-webhook').onclick = () => { if (!webhook || uncertain || !window.confirm('Remove this endpoint? Accepted deliveries and their original secrets remain dispatchable and replayable.')) return;
    act(async active => { await request(formPath() + '/webhook', { method: 'DELETE' }); if (!active()) return; await loadWebhook(active); message('Endpoint removed. Accepted deliveries are retained.'); }); };
  $('acknowledge-integration').onclick = () => { if (window.confirm('Have you reloaded metadata and reconciled the uncertain change, including revoking any unclaimed token?')) { uncertain = false; controls(); } };
  document.addEventListener('visibilitychange', () => { if (document.hidden) reset(); });
  window.addEventListener('pagehide', reset);
  return { reset, controls };
}
