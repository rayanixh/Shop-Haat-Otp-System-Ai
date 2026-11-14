<?php
/**
 * Deployment environment for this installation.
 *
 * Copy this file to config/environment.php on the server and set the value.
 * No other code change is needed.
 *
 *   'production'  (default) Real shop. Offline OTP is refused, codes are never
 *                 returned by any API or admin screen, and a missing SMS
 *                 provider is reported as a configuration error.
 *
 *   'development' Local / staging / test copy. The offline OTP driver may be
 *                 used and Admin → Security can display the generated test
 *                 code. Never use this value on a live store.
 *
 * If this file is absent the deployment is treated as production. You can also
 * set the SH_ENVIRONMENT environment variable instead, but a file is the most
 * portable option on shared hosting / cPanel.
 */

return 'production';
