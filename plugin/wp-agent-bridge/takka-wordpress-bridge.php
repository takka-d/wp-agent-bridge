<?php
/**
 * Plugin Name: WP Agent Bridge
 * Description: Secure WordPress management bridge for ChatGPT using GitHub App connectivity, guarded writes, previews, and rollback.
 * Version: 1.1.5
 * Author: TakKa
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: WP Agent Bridge License 1.0
 * Text Domain: wp-agent-bridge
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-takka-wordpress-bridge-envelope.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v04.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v05-search-replace.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v05-admin.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v05.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-idempotency.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-error-normalizer.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v06-self-update.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v06-self-update-safe.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v06-idempotency.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v06.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-audit.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-users.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-roles.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-user-meta.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-terms.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07-settings.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v07.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v071.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v072.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v073.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08-roles.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08-options.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08-option-delete.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08-post-terms.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08-guards.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v08.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v081.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v082.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v083-post-meta.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v083.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v084-post-content.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v084.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v085-content-batch.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v085.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v086-table.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v086.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v087-table-locator.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v087.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v088-table-headers.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v088.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v089-secure.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v090-secure-status.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v093-public-readiness.php';

// Self-contained runtime. All GitHub App credentials remain on this WordPress
// installation and normal runtime traffic goes directly between the user's own
// private repository and this site. No operator-owned relay is required.
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-github.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-github-recovery.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-runtime.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-runtime-v2.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-webhook-loop-guard.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-media.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-runtime-identity.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-hardening.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-direct-onboarding-guard.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-runtime-media-chunks.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-abilities-fallback.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-mcp-server.php';
require_once __DIR__ . '/includes/class-takka-wordpress-bridge-onboarding.php';

TakKa_WordPress_Bridge_Envelope::init();
TakKa_WordPress_Bridge::init();
TakKa_WordPress_Bridge_V04::init();
TakKa_WordPress_Bridge_V05::init();
TakKa_WordPress_Bridge_Idempotency::init();
TakKa_WordPress_Bridge_Error_Normalizer::init();
TakKa_WordPress_Bridge_V06::init();
TakKa_WordPress_Bridge_V07::init();
TakKa_WordPress_Bridge_V071::init();
TakKa_WordPress_Bridge_V072::init();
TakKa_WordPress_Bridge_V073::init();
TakKa_WordPress_Bridge_V08_Guards::init();
TakKa_WordPress_Bridge_V08::init();
TakKa_WordPress_Bridge_V081::init();
TakKa_WordPress_Bridge_V082::init();
TakKa_WordPress_Bridge_V083::init();
TakKa_WordPress_Bridge_V084::init();
TakKa_WordPress_Bridge_V085::init();
TakKa_WordPress_Bridge_V086::init();
TakKa_WordPress_Bridge_V087::init();
TakKa_WordPress_Bridge_V088::init();
TakKa_WordPress_Bridge_V089_Secure::init();
TakKa_WordPress_Bridge_V090_Secure_Status::init();
TakKa_WordPress_Bridge_V093_Public_Readiness::init();
TakKa_WordPress_Bridge_Direct_Runtime::init();
TakKa_WordPress_Bridge_Direct_Runtime_V2::init();
TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::init();
TakKa_WordPress_Bridge_Direct_Media::init();
TakKa_WordPress_Bridge_Direct_Hardening::init();
TakKa_WordPress_Bridge_Direct_Onboarding_Guard::init();
TakKa_WordPress_Bridge_Runtime_Media_Chunks::init();
TakKa_WordPress_Bridge_Abilities_Fallback::init();
TakKa_WordPress_Bridge_MCP_Server::init();
TakKa_WordPress_Bridge_Onboarding::init();
