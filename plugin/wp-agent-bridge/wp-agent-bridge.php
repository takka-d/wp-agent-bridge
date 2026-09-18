<?php
/**
 * Plugin Name: WP Agent Bridge
 * Description: Secure WordPress management bridge for ChatGPT using GitHub App connectivity, guarded writes, previews, and rollback.
 * Version: 1.1.40
 * Author: WP Agent Bridge
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: WP Agent Bridge License 1.0
 * Text Domain: wp-agent-bridge
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-wp-agent-bridge-envelope.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v04.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-theme-patch.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-theme-patch-diagnostics.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v05-search-replace.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v05-admin.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v05.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-idempotency.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-error-normalizer.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v06-self-update.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v06-self-update-safe.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v06-self-update-source.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v06-idempotency.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v06.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-audit.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-users.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-roles.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-user-meta.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-terms.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07-settings.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v07.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v071.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v072.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v073.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08-roles.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08-options.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08-option-delete.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08-post-terms.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08-guards.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v08.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v081.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v082.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v083-post-meta.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v083.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v084-post-content.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v084.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v085-content-batch.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v085.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v086-table.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v086.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v087-table-locator.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v087.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v088-table-headers.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v088.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v089-secure.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v090-secure-status.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v093-public-readiness.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v094-diagnostics.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v095-outline.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v095-html.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v095-classic.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v095-compat.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v096-site.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v096-media-hook.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v096-theme-files.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v096-health.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v097-workspace.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v098-read-batch.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-uploads-json.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v099-operations.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v099-policy.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-update-concurrency.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-content-diagnostics.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-revisions.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-revisions-pre-dispatch.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-v099-response-contract.php';

// Self-contained runtime. All GitHub App credentials remain on this WordPress
// installation and normal runtime traffic goes directly between the user's own
// private repository and this site. No operator-owned relay is required.
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-github.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-github-recovery.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-runtime.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-runtime-v2.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-invalid-pending.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-pending-reconciler.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-webhook-loop-guard.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-media.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-media-auto-path.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-media-pre-dispatch.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-integrity.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-runtime-identity.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-runtime-capabilities.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-runtime-guidance.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-runtime-bootstrap.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-concurrency-runtime-guidance.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-post-reliability-runtime-guidance.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-hardening.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-direct-onboarding-guard.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-runtime-media-chunks.php';
require_once __DIR__ . '/includes/class-wp-agent-bridge-onboarding.php';

WP_Agent_Bridge_Envelope::init();
WP_Agent_Bridge::init();
WP_Agent_Bridge_V04::init();
WP_Agent_Bridge_Theme_Patch::init();
WP_Agent_Bridge_Theme_Patch_Diagnostics::init();
WP_Agent_Bridge_V05::init();
WP_Agent_Bridge_Idempotency::init();
WP_Agent_Bridge_Error_Normalizer::init();
WP_Agent_Bridge_V06::init();
WP_Agent_Bridge_V07::init();
WP_Agent_Bridge_V071::init();
WP_Agent_Bridge_V072::init();
WP_Agent_Bridge_V073::init();
WP_Agent_Bridge_V08_Guards::init();
WP_Agent_Bridge_V08::init();
WP_Agent_Bridge_V081::init();
WP_Agent_Bridge_V082::init();
WP_Agent_Bridge_V083::init();
WP_Agent_Bridge_V084::init();
WP_Agent_Bridge_V085::init();
WP_Agent_Bridge_V086::init();
WP_Agent_Bridge_V087::init();
WP_Agent_Bridge_V088::init();
WP_Agent_Bridge_V089_Secure::init();
WP_Agent_Bridge_V090_Secure_Status::init();
WP_Agent_Bridge_V093_Public_Readiness::init();
WP_Agent_Bridge_V094_Diagnostics::init();
WP_Agent_Bridge_V095_Compat::init();
WP_Agent_Bridge_V096_Site::init();
WP_Agent_Bridge_V096_Theme_Files::init();
WP_Agent_Bridge_V096_Health::init();
WP_Agent_Bridge_V097_Workspace::init();
WP_Agent_Bridge_V098_Read_Batch::init();
WP_Agent_Bridge_V099_Operations::init();
WP_Agent_Bridge_V099_Policy::init();
WP_Agent_Bridge_Post_Update_Concurrency::init();
WP_Agent_Bridge_Post_Content_Diagnostics::init();
WP_Agent_Bridge_Post_Revisions::init();
WP_Agent_Bridge_Post_Revisions_Pre_Dispatch::init();
WP_Agent_Bridge_V099_Response_Contract::init();
WP_Agent_Bridge_Direct_Runtime::init();
WP_Agent_Bridge_Direct_Runtime_V2::init();
WP_Agent_Bridge_Direct_Invalid_Pending::init();
WP_Agent_Bridge_Direct_Pending_Reconciler::init();
WP_Agent_Bridge_Direct_Webhook_Loop_Guard::init();
WP_Agent_Bridge_Direct_Media::init();
WP_Agent_Bridge_Direct_Media_Auto_Path::init();
WP_Agent_Bridge_Direct_Media_Pre_Dispatch::init();
WP_Agent_Bridge_Integrity::init();
WP_Agent_Bridge_Runtime_Capabilities::init();
WP_Agent_Bridge_Runtime_Guidance::init();
WP_Agent_Bridge_Runtime_Bootstrap::init();
WP_Agent_Bridge_Post_Concurrency_Runtime_Guidance::init();
WP_Agent_Bridge_Post_Reliability_Runtime_Guidance::init();
WP_Agent_Bridge_V096_Media_Hook::init();
WP_Agent_Bridge_Direct_Hardening::init();
WP_Agent_Bridge_Direct_Onboarding_Guard::init();
WP_Agent_Bridge_Runtime_Media_Chunks::init();
WP_Agent_Bridge_Onboarding::init();
