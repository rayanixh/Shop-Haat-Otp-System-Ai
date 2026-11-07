(() => {
  'use strict';
  const root = document.querySelector('[data-support-settings]');
  if (!root) return;

  const grow = (textarea) => {
    if (textarea.closest('[hidden]')) return;
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.max(textarea.scrollHeight, textarea.offsetHeight)}px`;
  };

  // Existing accordion pattern: open only the section named by the URL hash.
  const sections = Array.from(root.querySelectorAll('details[data-section]'));
  const openForHash = () => {
    const hash = (window.location.hash || '').replace(/^#/, '').split('?')[0];
    if (!hash) return;
    sections.forEach((section) => {
      if (section.dataset.section === hash || section.id === hash) section.open = true;
    });
  };
  window.addEventListener('hashchange', openForHash);
  openForHash();
  sections.forEach((section) => {
    section.addEventListener('toggle', () => {
      if (section.open) section.querySelectorAll('textarea').forEach(grow);
    });
  });

  // Existing AI provider/model filtering, unchanged.
  const provider = root.querySelector('[data-provider-select]');
  const model = root.querySelector('[data-model-select]');
  if (provider && model) {
    const defaultProviderId = Number(provider.dataset.defaultProviderId || 0);
    const options = Array.from(model.options);
    const updateModels = (resetInvalid) => {
      const chosenProviderId = Number(provider.value) > 0 ? Number(provider.value) : defaultProviderId;
      options.forEach((option) => {
        const modelProviderId = Number(option.dataset.providerId || 0);
        option.hidden = option.value !== '' && (chosenProviderId <= 0 || modelProviderId !== chosenProviderId);
      });
      const selected = model.selectedOptions[0];
      if (resetInvalid && selected && selected.value !== '' && Number(selected.dataset.providerId) !== chosenProviderId) {
        model.value = '';
      }
    };
    provider.addEventListener('change', () => updateModels(true));
    updateModels(false);
  }

  // Only the selected SMS provider's fields are visible and submitted.
  const smsProvider = root.querySelector('[data-sms-provider-select]');
  const panels = Array.from(root.querySelectorAll('[data-sms-provider-panel]'));
  const setPanel = (panel, active) => {
    panel.hidden = !active;
    panel.querySelectorAll('input, select, textarea, button').forEach((field) => {
      field.disabled = !active;
    });
    if (active) panel.querySelectorAll('textarea').forEach(grow);
  };
  const updateSmsPanels = () => {
    const selected = smsProvider ? smsProvider.value : 'offline';
    panels.forEach((panel) => setPanel(panel, panel.dataset.smsProviderPanel === selected));
  };
  if (smsProvider) {
    smsProvider.addEventListener('change', updateSmsPanels);
    updateSmsPanels();
  }

  Array.from(root.querySelectorAll('textarea')).forEach((textarea) => {
    grow(textarea);
    textarea.addEventListener('input', () => grow(textarea));
  });

  root.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.dataset.confirm || 'Are you sure?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });
})();
