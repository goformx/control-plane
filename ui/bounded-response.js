// Fetch decodes compressed bodies, but exposes the wire Content-Length. Always
// cap decoded bytes; compare that header with body bytes only for identity data.
export async function readBoundedResponse(response, maxBytes) {
  if (!Number.isSafeInteger(maxBytes) || maxBytes < 1) throw new TypeError('Invalid response budget.');
  const declared = response.headers.get('Content-Length');
  const length = declared === null ? null : (/^(0|[1-9][0-9]*)$/.test(declared) ? Number(declared) : NaN);
  const encoding = response.headers.get('Content-Encoding')?.trim().toLowerCase();
  const identity = !encoding || encoding === 'identity';
  if (length !== null && (!Number.isSafeInteger(length) || (identity && length > maxBytes))) {
    await response.body?.cancel();
    throw new Error('Response exceeds its supported size or has invalid length.');
  }
  if (!response.body) {
    if (identity && length === 0) return new Blob([]);
    throw new Error('Response body is incomplete.');
  }
  const reader = response.body.getReader();
  const chunks = [];
  let bytes = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.byteLength;
      if (bytes > maxBytes || (identity && length !== null && bytes > length)) {
        throw new Error('Response exceeds its supported size or declared length.');
      }
      chunks.push(value);
    }
    if (identity && length !== null && bytes !== length) throw new Error('Response body is incomplete.');
    return new Blob(chunks);
  } catch (error) {
    await reader.cancel().catch(() => {});
    throw error;
  } finally {
    reader.releaseLock();
  }
}
