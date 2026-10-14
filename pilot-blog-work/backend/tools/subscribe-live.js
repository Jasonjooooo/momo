/* ============ 06: subscribe ============ */
/* Live state: the form posts to api/subscribe.php, which stores the address (no mail is sent by any code;
   the author mails new posts by hand). Consent is a required checkbox; the hidden website field is a honeypot. */
const subForm = $('#sub-form');
const subEmail = $('#sub-email');
const subErr = $('#sub-err');
const subBtn = $('#sub-btn');
const subDone = $('#sub-done');
const subConsent = $('#sub-consent');
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
let subPending = false;
function subError(msg) {
  subErr.textContent = msg;
  subEmail.setAttribute('aria-invalid', msg ? 'true' : 'false');
}
function subShow(text, inop) {
  subDone.textContent = '';
  const p = document.createElement('p');
  if (inop) { const pl = document.createElement('span'); pl.className = 'placard'; pl.textContent = 'INOP'; pl.setAttribute('aria-hidden', 'true'); p.append(pl); }
  p.append(document.createTextNode(text));
  subDone.append(p);
  subForm.hidden = true;
  subDone.hidden = false;
  subDone.focus({ preventScroll: true });
}
subEmail.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.isComposing || e.keyCode === 229)) e.preventDefault(); });
subEmail.addEventListener('input', () => { if (subErr.textContent) subError(''); });
if (subConsent) subConsent.addEventListener('change', () => { if (subConsent.checked && subErr.textContent === T.subConsent) subError(''); });
subForm.addEventListener('submit', async e => {
  e.preventDefault();
  if (subPending) return;
  const v = subEmail.value.trim();
  if (!v) { subError(T.subEmpty); subEmail.focus(); return; }
  if (!EMAIL_RE.test(v)) { subError(T.subFormat); subEmail.focus(); return; }
  if (subConsent && !subConsent.checked) { subError(T.subConsent); subConsent.focus(); return; }
  subError('');
  // aria-disabled plus the subPending guard, never the disabled attribute: disabling the focused button drops focus to <body>
  subPending = true; subBtn.setAttribute('aria-disabled', 'true'); subBtn.textContent = T.subBusy;
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 20000);
  try {
    const fd = new FormData(subForm);
    fd.set('email', v);
    fd.set('t', String(Math.round(performance.now())));
    const res = await fetch(SUBSCRIBE_ENDPOINT, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, signal: ctrl.signal });
    let data = null;
    try { data = await res.json(); } catch (_) { data = null; }
    const msg = data && typeof data.message === 'string' ? data.message.trim() : '';
    if (res.ok && data && data.ok === true) subShow(T.subOkPre + v + T.subOkPost, false);
    else if (msg) subError(msg);                       // api/subscribe.php answers errors as {error, message}; message is user-facing Chinese
    else if (res.status === 429) subError(T.subRate);
    else subError(T.subFail);
  } catch (_) {
    subError(T.subFail);                              // offline, timed out, or the connection dropped
  } finally {
    clearTimeout(timer);
    subPending = false; subBtn.setAttribute('aria-disabled', 'false'); subBtn.textContent = '{{SUB_BUTTON}}';
  }
});

