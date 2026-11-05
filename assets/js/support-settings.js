(() => {
  'use strict';
  const root = document.querySelector('[data-support-settings]');
  if (!root) return;

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

  const textareas = Array.from(root.querySelectorAll('textarea'));
  const grow = (textarea) => {
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.max(textarea.scrollHeight, textarea.offsetHeight)}px`;
  };
  textareas.forEach((textarea) => {
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
