<?php
/**
 * Shared OTP verification modal for signup/login.
 *
 * Expects, before inclusion: $otpPurpose ('signup'|'login'), $otpPhone (canonical),
 * $otpMasked (display mask), $otpVerifyLabel, and optionally $otpLead.
 */
if (!isset($otpPurpose) || !in_array($otpPurpose, ['signup', 'login'], true)) { return; }
$otpPhone  = $otpPhone ?? '';
$otpMasked = $otpMasked !== '' ? $otpMasked : sh_phone_mask_login($otpPhone);
$otpVerifyLabel = $otpVerifyLabel ?? 'Verify & Continue';
$otpAlreadySent = $otpAlreadySent ?? true; // auto-open only when the server already sent the code
$otpLength = (int)sh_otp_length();
$otpMinutes = (int)ceil(sh_otp_expiry_seconds() / 60);
?>
<div class="sh-modal" data-otp-modal hidden>
  <div class="sh-modal__scrim" data-otp-modal-close></div>
  <div class="sh-modal__card" role="dialog" aria-modal="true" aria-labelledby="sh-otp-title">
    <button class="sh-modal__x" type="button" data-otp-modal-close aria-label="Close"><?= sh_icon('x', 18) ?></button>
    <div class="sh-otp" data-otp
         data-purpose="<?= e($otpPurpose) ?>"
         data-phone="<?= e($otpPhone) ?>"
         data-length="<?= $otpLength ?>"
         data-expires="<?= (int)sh_otp_expiry_seconds() ?>"
         data-cooldown="<?= (int)sh_otp_resend_cooldown() ?>"
         data-already-sent="<?= $otpAlreadySent ? '1' : '0' ?>">
      <div class="sh-otp__icon"><?= sh_icon('shield', 24) ?></div>
      <h2 class="sh-otp__title" id="sh-otp-title">Verify Your Phone</h2>
      <p class="sh-otp__phone-label">We sent a <?= $otpLength ?>-digit code to<br><strong><?= e($otpMasked) ?></strong></p>

      <div class="sh-otp__boxes" data-otp-boxes aria-label="Verification code">
        <?php for ($i = 0; $i < $otpLength; $i++): ?>
          <input class="sh-otp__box" type="text" inputmode="numeric" autocomplete="one-time-code"
                 maxlength="1" data-otp-digit aria-label="Digit <?= $i + 1 ?>">
        <?php endfor; ?>
      </div>

      <button class="sh-btn sh-btn--lg sh-btn--block" type="button" data-otp-verify>
        <?= sh_icon('check-circle', 16) ?><span data-otp-verify-label><?= e($otpVerifyLabel) ?></span>
      </button>

      <button class="sh-btn sh-btn--lg sh-btn--block sh-btn--ghost" type="button" data-otp-send>
        <?= sh_icon('send', 16) ?><span data-otp-send-label>Resend code</span>
      </button>

      <div class="sh-alert sh-alert--error" data-otp-error hidden><?= sh_icon('x-circle', 16) ?><span></span></div>
      <p class="sh-otp__hint-count" data-otp-expiry>Code expires after <?= $otpMinutes ?> minutes. Did not receive it? Use “Resend code”.</p>
    </div>
  </div>
</div>
