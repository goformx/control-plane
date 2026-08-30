// CodeMirror virtualizes its DOM: innerText is not the complete document.
// Exercise select-all and its copy handler with an in-memory clipboard event,
// without reading or overwriting the operating system clipboard.
export async function readSchemaEditor(page) {
  const editor = page.getByRole('textbox', { name: 'JSON Schema editor' });
  await editor.focus();
  await editor.press('ControlOrMeta+a');
  return editor.evaluate(element => {
    const clipboardData = new DataTransfer();
    element.dispatchEvent(new ClipboardEvent('copy', { clipboardData, bubbles: true, cancelable: true }));
    const text = clipboardData.getData('text/plain');
    if (!text) throw new Error('Editor copy did not yield a document');
    return text;
  });
}
