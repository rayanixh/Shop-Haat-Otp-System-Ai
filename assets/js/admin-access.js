/**
 * Live, secret-free preview and client-side validation for the custom Admin
 * URL. The server remains authoritative: it normalizes uppercase, validates
 * reserved routes / collisions, enforces CSRF and Super Admin authorization,
 * and requires a verified same-session visit before activation.
 */
(() => {
  const form = document.querySelector('[data-admin-access-form]');
  if (!form) return;

  const input = form.querySelector('[data-admin-access-path]');
  const preview = form.querySelector('[data-admin-access-preview]');
  const copy = form.querySelector('[data-admin-access-preview-copy]');
  const hint = form.querySelector('[data-admin-access-hint]');
  if (!input || !preview || !copy || !hint) return;

  const origin = String(form.getAttribute('data-admin-access-origin') || '');
  let reserved = [];
  try {
    const parsed = JSON.parse(form.getAttribute('data-admin-access-reserved') || '[]');
    if (Array.isArray(parsed)) reserved = parsed.map((value) => String(value).toLowerCase());
  } catch (_) {
    reserved = [];
  }

  const validate = (raw) => {
    let value = String(raw || '').trim();
    if (value.startsWith('/')) value = value.slice(1);
    if (value.endsWith('/')) value = value.slice(0, -1);
    const path = value.toLowerCase();
    if (value === '') return { error: 'Enter a path to preview the complete URL.', path: '' };
    if (value.length > 130 || /[\/\\%?#:.]/u.test(value) || /\s/u.test(value)) {
      return { error: 'Use exactly one URL-safe path. Domains, queries, fragments, encoded traversal and extra segments are not allowed.', path: '' };
    }
    if (!/^[A-Za-z0-9_-]+$/u.test(value)) {
      return { error: 'Use only letters, numbers, hyphens and underscores.', path: '' };
    }
    if (path.length < 3 || path.length > 80) {
      return { error: 'Use 3–80 characters.', path: '' };
    }
    if (!/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/u.test(path)) {
      return { error: 'Start and end with a letter or number.', path: '' };
    }
    if (reserved.includes(path)) {
      return { error: 'That path is reserved by the application. Choose a different route.', path: '' };
    }
    return { error: '', path };
  };

  const update = () => {
    const state = validate(input.value);
    if (state.error) {
      input.setCustomValidity(state.error);
      preview.textContent = state.error;
      copy.setAttribute('data-copy', '');
      copy.disabled = true;
      hint.textContent = state.error;
      return;
    }
    input.setCustomValidity('');
    const fullUrl = origin + state.path;
    preview.textContent = fullUrl;
    copy.setAttribute('data-copy', fullUrl);
    copy.disabled = false;
    const normalized = input.value.trim() !== state.path;
    hint.textContent = normalized
      ? 'Uppercase is normalized to lowercase: the final URL uses ' + state.path + '.'
      : 'The complete URL is shown here before saving.';
  };

  input.addEventListener('input', update);
  input.addEventListener('change', update);
  form.addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (submitter && (submitter.hasAttribute('formnovalidate') || submitter.value === 'admin_access_suggest')) {
      return;
    }
    const state = validate(input.value);
    if (!state.error) return;
    event.preventDefault();
    input.setCustomValidity(state.error);
    if (typeof input.reportValidity === 'function') input.reportValidity();
    update();
  });

  update();
})();
