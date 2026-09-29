import test from 'node:test';
import assert from 'node:assert/strict';
import { readBoundedResponse } from '../../ui/bounded-response.js';

function response(chunks, length, encoding) {
  let reads = 0, cancelled = false;
  const body = new ReadableStream({
    pull(controller) {
      if (reads === chunks.length) { controller.close(); return; }
      controller.enqueue(new TextEncoder().encode(chunks[reads++]));
    },
    cancel() { cancelled = true; },
  });
  return { value: { headers: new Headers({ ...(length === undefined ? {} : { 'Content-Length': length }),
    ...(encoding === undefined ? {} : { 'Content-Encoding': encoding }) }), body },
    get reads() { return reads; }, get cancelled() { return cancelled; } };
}

test('accepts below and exactly at the budget with and without a declared length', async () => {
  for (const length of [undefined, '3', '4']) {
    const chunks = length === '4' ? ['ab', 'cd'] : ['a', 'bc'];
    assert.equal(await (await readBoundedResponse(response(chunks, length).value, 4)).text(), chunks.join(''));
  }
});

test('rejects advertised oversize before reading and streamed oversize early', async () => {
  const advertised = response(['secret'], '5');
  await assert.rejects(readBoundedResponse(advertised.value, 4), /size/);
  assert.equal(advertised.reads, 0);
  const streamed = response(['ab', 'cde', 'unread'], undefined);
  await assert.rejects(readBoundedResponse(streamed.value, 4), /size/);
  assert.ok(streamed.reads < 3);
  assert.equal(streamed.cancelled, true);
});

test('rejects false length and early EOF', async () => {
  await assert.rejects(readBoundedResponse(response(['abc'], 'bogus').value, 4), /length/);
  await assert.rejects(readBoundedResponse(response(['abc'], '4').value, 4), /incomplete/);
});

test('caps decoded gzip bytes without comparing them to compressed wire length', async () => {
  assert.equal(await (await readBoundedResponse(response(['decoded'], '5', 'gzip').value, 7)).text(), 'decoded');
  assert.equal(await (await readBoundedResponse(response(['ok'], '12', 'gzip').value, 2)).text(), 'ok');
  const oversized = response(['abcd', 'ef', 'unread'], '3', 'gzip');
  await assert.rejects(readBoundedResponse(oversized.value, 5), /size/);
  assert.ok(oversized.reads < 3);
  assert.equal(oversized.cancelled, true);
  await assert.rejects(readBoundedResponse(response(['ok'], 'bad', 'gzip').value, 5), /length/);
});

test('propagates stream abort and cancels the reader', async () => {
  let cancelled = false;
  const body = new ReadableStream({
    pull(controller) { controller.error(new DOMException('aborted', 'AbortError')); },
    cancel() { cancelled = true; },
  });
  await assert.rejects(readBoundedResponse({ headers: new Headers(), body }, 4), /aborted/);
  assert.equal(cancelled, false); // Erroring a stream closes it before cancellation can run.
});
