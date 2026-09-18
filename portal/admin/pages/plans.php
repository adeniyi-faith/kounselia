<?php
/**
 * Kounselia Admin — Plans & Pricing.
 *
 * Lets an admin create, edit, and retire the paid plans members see on
 * the dashboard's Upgrade tab (name, price, features, active/popular
 * flags). Stored as a single wp_option ('kounselia_plans'), the same
 * pattern counselors.php uses for kounselia_counselors — this is
 * admin-configured content, not a per-user relational fact, so it
 * doesn't need its own database table. See
 * portal/wp-content/mu-plugins/kounselia/includes/payments.php for the
 * read helpers (kounselia_get_plans/kounselia_get_plan) the front-end
 * checkout uses.
 */
$kounselia_admin_active = 'plans';
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';

$kounselia_notice = '';
$kounselia_error  = '';

$plans = get_option( 'kounselia_plans', null );
if ( ! is_array( $plans ) ) {
    $plans = function_exists( 'kounselia_default_plans' ) ? kounselia_default_plans() : array();
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['kounselia_action'] ) ) {
    $kounselia_action = sanitize_key( $_POST['kounselia_action'] );

    if ( 'save_plan' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_plans' ) ) {

        $name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $price_amount = isset( $_POST['price_amount'] ) ? (float) $_POST['price_amount'] : 0;
        $currency     = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'NGN';
        $interval     = ( isset( $_POST['interval'] ) && 'yearly' === $_POST['interval'] ) ? 'yearly' : 'monthly';
        $raw_features = isset( $_POST['features'] ) ? (string) wp_unslash( $_POST['features'] ) : '';
        $features     = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $raw_features ) ) ) ) );
        $is_active    = ! empty( $_POST['is_active'] ) ? 1 : 0;
        $is_popular   = ! empty( $_POST['is_popular'] ) ? 1 : 0;
        $sort_order   = isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0;
        $existing_id  = isset( $_POST['plan_id'] ) ? sanitize_key( $_POST['plan_id'] ) : '';

        if ( '' === $name ) {
            $kounselia_error = 'Please enter a plan name.';
        } elseif ( $price_amount <= 0 ) {
            $kounselia_error = 'Please enter a price greater than zero.';
        } else {
            if ( $existing_id && isset( $plans[ $existing_id ] ) ) {
                $plan_id = $existing_id;
            } else {
                $base_id = sanitize_title( $name );
                $plan_id = $base_id;
                $suffix  = 2;
                while ( isset( $plans[ $plan_id ] ) ) {
                    $plan_id = $base_id . '-' . $suffix;
                    $suffix++;
                }
            }

            $plans[ $plan_id ] = array(
                'id'           => $plan_id,
                'name'         => $name,
                'price_amount' => $price_amount,
                'currency'     => $currency ?: 'NGN',
                'interval'     => $interval,
                'features'     => $features,
                'is_active'    => $is_active,
                'is_popular'   => $is_popular,
                'sort_order'   => $sort_order,
            );

            update_option( 'kounselia_plans', $plans );
            kounselia_admin_log( $existing_id ? 'update_plan' : 'create_plan', 'plan', 0 );
            $kounselia_notice = 'Plan "' . $name . '" saved.';
        }

    } elseif ( 'delete_plan' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_plans' ) ) {

        $plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( $_POST['plan_id'] ) : '';
        if ( isset( $plans[ $plan_id ] ) ) {
            $deleted_name = $plans[ $plan_id ]['name'];
            unset( $plans[ $plan_id ] );
            update_option( 'kounselia_plans', $plans );
            kounselia_admin_log( 'delete_plan', 'plan', 0 );
            $kounselia_notice = 'Plan "' . $deleted_name . '" deleted. Members already subscribed to it keep their access until it expires.';
        }
    }
}

uasort( $plans, function ( $a, $b ) {
    return ( (int) ( $a['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['sort_order'] ?? 0 ) );
} );

$edit_plan_id = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
$edit_plan    = ( $edit_plan_id && isset( $plans[ $edit_plan_id ] ) ) ? $plans[ $edit_plan_id ] : null;

$form_id            = $edit_plan ? $edit_plan['id'] : '';
$form_name          = $edit_plan ? $edit_plan['name'] : '';
$form_price         = $edit_plan ? $edit_plan['price_amount'] : '';
$form_currency      = $edit_plan ? $edit_plan['currency'] : 'NGN';
$form_interval      = $edit_plan ? $edit_plan['interval'] : 'monthly';
$form_features      = $edit_plan ? implode( "\n", (array) $edit_plan['features'] ) : '';
$form_is_active     = $edit_plan ? ! empty( $edit_plan['is_active'] ) : true;
$form_is_popular    = $edit_plan ? ! empty( $edit_plan['is_popular'] ) : false;
$form_sort_order    = $edit_plan ? $edit_plan['sort_order'] : ( count( $plans ) + 1 );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Plans &amp; Pricing — Kounselia Admin</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
  .plans-admin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:8px}
  .plan-admin-card{background:var(--bg2,#F8FAFC);border:1px solid var(--border);border-radius:10px;padding:18px;position:relative}
  .plan-admin-card.inactive{opacity:.55}
  .plan-admin-badge{position:absolute;top:12px;right:12px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:50px;background:var(--gold);color:#fff}
  .plan-admin-card h3{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:500;margin-bottom:4px;padding-right:70px}
  .plan-admin-price{font-size:14px;color:var(--text2);margin-bottom:12px}
  .plan-admin-features{list-style:none;padding:0;margin:0 0 14px;display:flex;flex-direction:column;gap:6px}
  .plan-admin-features li{font-size:12.5px;color:var(--text2);display:flex;gap:6px;align-items:flex-start}
  .plan-admin-features li i{color:var(--sage);margin-top:1px;flex-shrink:0}
  .plan-admin-actions{display:flex;gap:8px}
  .plan-admin-actions a, .plan-admin-actions button{font-size:12.5px;padding:7px 14px;border-radius:6px;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-block;}
  .plan-admin-edit{background:var(--bg);border:1px solid var(--border);color:var(--text1);}
  .plan-admin-delete{background:none;border:1px solid var(--rose);color:var(--rose);}
  .plan-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:640px){.plan-form-grid{grid-template-columns:1fr}}
  .plan-form-check{display:flex;align-items:center;gap:8px;font-size:13.5px;color:var(--text1);margin-top:8px}
  textarea.bulk-input{width:100%;min-height:100px;padding:10px;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:13px;color:var(--text1);background:var(--bg);resize:vertical;margin-bottom:6px}
  textarea.bulk-input:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(30,58,95,0.08)}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Plans &amp; Pricing</h1>
  <p class="admin-subtitle">Create and edit the paid plans members can subscribe to via Paystack from their dashboard.</p>

  <?php if ( $kounselia_notice ) : ?>
    <div class="login-msg notice" style="margin-bottom:20px;"><?php echo esc_html( $kounselia_notice ); ?></div>
  <?php endif; ?>
  <?php if ( $kounselia_error ) : ?>
    <div class="login-msg error" style="margin-bottom:20px;"><?php echo esc_html( $kounselia_error ); ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">
      Current Plans
      <span style="font-weight:400;color:var(--text3);font-size:12px;"><?php echo count( $plans ); ?> configured</span>
    </div>

    <?php if ( empty( $plans ) ) : ?>
      <div class="empty-state" style="padding:24px;text-align:center;">No plans yet — add one below.</div>
    <?php else : ?>
      <div class="plans-admin-grid">
        <?php foreach ( $plans as $plan ) : ?>
          <div class="plan-admin-card <?php echo empty( $plan['is_active'] ) ? 'inactive' : ''; ?>">
            <?php if ( ! empty( $plan['is_popular'] ) ) : ?><span class="plan-admin-badge">Popular</span><?php endif; ?>
            <h3><?php echo esc_html( $plan['name'] ); ?></h3>
            <div class="plan-admin-price">
              ₦<?php echo esc_html( number_format( (float) $plan['price_amount'] ) ); ?> / <?php echo 'yearly' === $plan['interval'] ? 'year' : 'month'; ?>
              · <?php echo empty( $plan['is_active'] ) ? 'Hidden from members' : 'Live'; ?>
            </div>
            <ul class="plan-admin-features">
              <?php foreach ( (array) $plan['features'] as $feature ) : ?>
                <li><i class="ti ti-check"></i> <?php echo esc_html( $feature ); ?></li>
              <?php endforeach; ?>
            </ul>
            <div class="plan-admin-actions">
              <a class="plan-admin-edit" href="?edit=<?php echo esc_attr( $plan['id'] ); ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete the &quot;<?php echo esc_js( $plan['name'] ); ?>&quot; plan? Members already on it keep access until their period ends.');" style="display:inline;">
                <?php wp_nonce_field( 'kounselia_settings_plans' ); ?>
                <input type="hidden" name="kounselia_action" value="delete_plan">
                <input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan['id'] ); ?>">
                <button type="submit" class="plan-admin-delete">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title"><?php echo $edit_plan ? 'Edit "' . esc_html( $form_name ) . '"' : 'Add a New Plan'; ?></div>
    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_plans' ); ?>
      <input type="hidden" name="kounselia_action" value="save_plan">
      <input type="hidden" name="plan_id" value="<?php echo esc_attr( $form_id ); ?>">

      <div class="plan-form-grid">
        <div class="login-field">
          <label for="name">Plan name</label>
          <input type="text" id="name" name="name" required value="<?php echo esc_attr( $form_name ); ?>" placeholder="Pro">
        </div>
        <div class="login-field">
          <label for="price_amount">Price (₦)</label>
          <input type="number" id="price_amount" name="price_amount" min="1" step="0.01" required value="<?php echo esc_attr( $form_price ); ?>" placeholder="4999">
        </div>
        <div class="login-field">
          <label for="interval">Billing interval</label>
          <select id="interval" name="interval" style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:14px;background:var(--bg);color:var(--text1);">
            <option value="monthly" <?php selected( $form_interval, 'monthly' ); ?>>Monthly</option>
            <option value="yearly" <?php selected( $form_interval, 'yearly' ); ?>>Yearly</option>
          </select>
        </div>
        <div class="login-field">
          <label for="sort_order">Display order</label>
          <input type="number" id="sort_order" name="sort_order" min="0" value="<?php echo esc_attr( $form_sort_order ); ?>">
        </div>
      </div>

      <div class="login-field">
        <label for="features">Features (one per line)</label>
        <textarea id="features" name="features" class="bulk-input" placeholder="Deep session memory across visits&#10;Structured 30 day programs&#10;Priority access to all counselors"><?php echo esc_textarea( $form_features ); ?></textarea>
      </div>

      <label class="plan-form-check"><input type="checkbox" name="is_active" value="1" <?php checked( $form_is_active ); ?>> Live — members can see and subscribe to this plan</label>
      <label class="plan-form-check"><input type="checkbox" name="is_popular" value="1" <?php checked( $form_is_popular ); ?>> Show a "Popular" badge</label>

      <div style="margin-top:20px;display:flex;gap:10px;">
        <button type="submit" class="login-submit" style="width:auto;padding:11px 22px;"><?php echo $edit_plan ? 'Save Changes' : 'Add Plan'; ?></button>
        <?php if ( $edit_plan ) : ?>
          <a href="/portal/admin/pages/plans.php" class="login-submit" style="width:auto;padding:11px 22px;background:var(--text3);text-decoration:none;text-align:center;">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
</body>
</html>
