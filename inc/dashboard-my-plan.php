<?php
/**
 * Member dashboard — "My plan" tab (id view-upgrade, kept so existing
 * ?tab=upgrade and ?sub=… links keep working).
 *
 * Shows where the member stands (Free / Pro / renewing / renewal off /
 * payment problem / ended), their saved card and next payment, what
 * each plan actually includes (the real limits from membership.php),
 * this month's usage, plan choices, and billing history — and lets them
 * turn auto-renew off/on, switch plan at renewal, remove their card,
 * or pay.
 *
 * Expects from dashboard.php: $user, $available_plans.
 */
$kp_summary  = function_exists( 'kounselia_subscription_summary' ) ? kounselia_subscription_summary( $user->ID ) : array( 'state' => 'none', 'sub' => null );
$kp_sub      = $kp_summary['sub'];
$kp_state    = $kp_summary['state'];
$kp_live     = in_array( $kp_state, array( 'active', 'renewal_off', 'payment_problem' ), true );
$kp_is_pro   = function_exists( 'kounselia_member_is_pro' ) && kounselia_member_is_pro( $user->ID );
$kp_gifted   = $kp_is_pro && ! $kp_live; // Pro granted by the team, no paid subscription.
$kp_benefits = function_exists( 'kounselia_plan_benefits' ) ? kounselia_plan_benefits() : null;
$kp_refl     = function_exists( 'kounselia_reflection_allowance' ) ? kounselia_reflection_allowance( $user->ID ) : null;
$kp_history  = function_exists( 'kounselia_get_billing_history' ) ? kounselia_get_billing_history( $user->ID ) : array();
$kp_money    = function ( $amount, $currency ) {
    return function_exists( 'kounselia_money' ) ? kounselia_money( $amount, $currency ) : '₦' . number_format( (float) $amount );
};
$kp_date     = function ( $mysql ) use ( $dash_lang ) {
    return kd_date( strtotime( $mysql ), 'F j, Y', 'd MMMM y', $dash_lang );
};
// Words in the member's language: $kp_t is escaped text; $kp_tr is for the few
// sentences that carry <b> markup, so every value passed to it must be escaped already.
$kp_t  = function ( $key, $vars = array() ) use ( $dash_lang ) {
    return esc_html( kounselia_t( $key, $vars, $dash_lang ) );
};
$kp_tr = function ( $key, $vars = array() ) use ( $dash_lang ) {
    return kounselia_t( $key, $vars, $dash_lang );
};
$kp_public_key = function_exists( 'kounselia_paystack_public_key' ) ? kounselia_paystack_public_key() : '';
// Plan prices are shown in the visitor's currency (naira or dollars, see
// currency.php); a subscriber's renewal price stays in the currency they pay in.
$kp_viewer_currency = function_exists( 'kounselia_viewer_currency' ) ? kounselia_viewer_currency() : 'NGN';
$kp_price = function ( $plan, $currency ) {
    return function_exists( 'kounselia_plan_price' ) ? kounselia_plan_price( $plan, $currency ) : (float) $plan['price_amount'];
};
?>
<div class="view-panel" id="view-upgrade">
  <section class="section">
    <div class="section-head"><h2><?php echo $kp_t( 'd.nav.plan' ); ?></h2><span class="section-sub"><?php echo $kp_t( $kp_is_pro ? 'd.plan.title_sub_pro' : 'd.plan.title_sub_free' ); ?></span></div>

    <?php /* ---------- Where you stand ---------- */ ?>
    <div class="plan-hero state-<?php echo esc_attr( $kp_gifted ? 'gifted' : $kp_state ); ?>">
      <div class="plan-hero-icon"><i class="ti <?php echo esc_attr( array( 'none' => 'ti-leaf', 'active' => 'ti-sparkles', 'renewal_off' => 'ti-clock-pause', 'payment_problem' => 'ti-alert-triangle', 'ended' => 'ti-hourglass-empty' )[ $kp_state ] ?? 'ti-sparkles' ); ?>"></i></div>
      <div class="plan-hero-meta">
        <?php if ( $kp_gifted ) : ?>
          <h3><?php echo $kp_t( 'd.plan.gift_title' ); ?></h3>
          <p><?php echo $kp_t( 'd.plan.gift_body' ); ?></p>
        <?php elseif ( 'active' === $kp_state ) : ?>
          <h3><?php echo $kp_t( 'd.plan.on', array( 'plan' => $kp_sub->plan_name ) ); ?></h3>
          <?php if ( $kp_summary['auto_renews'] ) : ?>
            <p><?php echo $kp_tr( $kp_summary['next_plan'] ? 'd.plan.renews_moving' : 'd.plan.renews', array(
              'date'   => '<b>' . esc_html( $kp_date( $kp_sub->current_period_end ) ) . '</b>',
              'amount' => esc_html( $kp_money( $kp_summary['renew_amount'], $kp_summary['currency'] ) ),
              'next'   => $kp_summary['next_plan'] ? esc_html( $kp_summary['next_plan']['name'] ) : '',
          ) ); ?></p>
          <?php else : ?>
            <p><?php echo $kp_tr( 'd.plan.runs_until', array( 'date' => '<b>' . esc_html( $kp_date( $kp_sub->current_period_end ) ) . '</b>' ) ); ?></p>
          <?php endif; ?>
        <?php elseif ( 'renewal_off' === $kp_state ) : ?>
          <h3><?php echo $kp_t( 'd.plan.until_title', array( 'plan' => $kp_sub->plan_name, 'date' => $kp_date( $kp_sub->current_period_end ) ) ); ?></h3>
          <p><?php echo $kp_t( 'd.plan.renewal_off' ); ?></p>
        <?php elseif ( 'payment_problem' === $kp_state ) : ?>
          <h3><?php echo $kp_t( 'd.plan.problem_title' ); ?></h3>
          <p><?php echo $kp_sub->last_renewal_error ? esc_html( $kp_sub->last_renewal_error ) . '. ' : ''; ?><?php echo $kp_t( (int) $kp_sub->renewal_attempts < KOUNSELIA_RENEWAL_MAX_ATTEMPTS ? 'd.plan.problem_retry' : 'd.plan.problem_pay', array( 'date' => $kp_date( $kp_sub->current_period_end ) ) ); ?></p>
        <?php elseif ( 'ended' === $kp_state ) : ?>
          <h3><?php echo $kp_t( 'd.plan.ended_title', array( 'plan' => $kp_sub->plan_name ) ); ?></h3>
          <p><?php echo $kp_t( 'd.plan.ended_body', array( 'date' => $kp_date( $kp_sub->current_period_end ) ) ); ?></p>
        <?php else : ?>
          <h3><?php echo $kp_t( 'd.plan.free_title' ); ?></h3>
          <p><?php echo $kp_t( 'd.plan.free_body' ); ?></p>
        <?php endif; ?>
      </div>
      <div class="plan-hero-actions">
        <?php if ( 'active' === $kp_state && $kp_summary['auto_renews'] ) : ?>
          <button class="sub-cancel-btn" id="sub-cancel-btn"><?php echo $kp_t( 'd.plan.turn_off' ); ?></button>
        <?php elseif ( 'renewal_off' === $kp_state && $kp_summary['has_card'] ) : ?>
          <button class="btn-plan primary" data-sub-action="kounselia_resume_subscription"><?php echo $kp_t( 'd.plan.turn_on' ); ?></button>
        <?php elseif ( 'payment_problem' === $kp_state ) : ?>
          <button class="btn-plan primary plan-subscribe-btn" data-plan-id="<?php echo esc_attr( $kp_sub->pending_plan_id ? $kp_sub->pending_plan_id : $kp_sub->plan_id ); ?>"><?php echo $kp_t( 'd.plan.pay_now' ); ?></button>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ---------- Card & next payment ---------- */ ?>
    <?php if ( $kp_live ) : ?>
      <div class="billing-grid">
        <div class="billing-box">
          <div class="billing-label"><?php echo $kp_t( 'd.plan.payment_method' ); ?></div>
          <?php if ( $kp_summary['has_card'] ) : ?>
            <div class="billing-card"><i class="ti ti-credit-card"></i> <?php echo esc_html( trim( ( $kp_sub->card_brand ? $kp_sub->card_brand : kounselia_t( 'd.plan.card', array(), $dash_lang ) ) . ' •••• ' . $kp_sub->card_last4 ) ); ?><?php echo $kp_sub->card_exp ? '<span>' . $kp_t( 'd.plan.expires', array( 'date' => $kp_sub->card_exp ) ) . '</span>' : ''; ?></div>
            <button class="link-btn" data-sub-action="kounselia_remove_subscription_card" data-confirm="<?php echo esc_attr( kounselia_t( 'd.plan.remove_confirm', array(), $dash_lang ) ); ?>"><?php echo $kp_t( 'd.plan.remove_card' ); ?></button>
          <?php else : ?>
            <div class="billing-card muted"><i class="ti ti-credit-card-off"></i> <?php echo $kp_t( 'd.plan.no_card' ); ?></div>
          <?php endif; ?>
        </div>
        <div class="billing-box">
          <div class="billing-label"><?php echo $kp_t( 'd.plan.next_payment' ); ?></div>
          <?php if ( $kp_summary['auto_renews'] ) : ?>
            <div class="billing-big"><?php echo esc_html( $kp_money( $kp_summary['renew_amount'], $kp_summary['currency'] ) ); ?></div>
            <div class="billing-sub"><?php echo $kp_t( 'd.plan.on_date', array( 'date' => $kp_date( $kp_sub->current_period_end ) ) ); ?></div>
          <?php else : ?>
            <div class="billing-big muted"><?php echo $kp_t( 'd.plan.none_scheduled' ); ?></div>
            <div class="billing-sub"><?php echo $kp_t( 'd.plan.no_more_charges' ); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ( count( $available_plans ) > 1 && in_array( $kp_state, array( 'active', 'payment_problem' ), true ) ) : ?>
        <div class="switch-box">
          <label for="switch-plan"><?php echo $kp_t( 'd.plan.change_label' ); ?></label>
          <div class="switch-row">
            <select id="switch-plan">
              <?php foreach ( $available_plans as $kp_plan ) : ?>
                <option value="<?php echo esc_attr( $kp_plan['id'] ); ?>" <?php selected( $kp_sub->pending_plan_id ? $kp_sub->pending_plan_id : $kp_sub->plan_id, $kp_plan['id'] ); ?>><?php echo $kp_t( 'yearly' === $kp_plan['interval'] ? 'd.plan.opt_year' : 'd.plan.opt_month', array( 'name' => $kp_plan['name'], 'price' => $kp_money( $kp_price( $kp_plan, $kp_summary['currency'] ), $kp_summary['currency'] ) ) ); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn-plan" id="switch-plan-btn"><?php echo $kp_t( 'd.plan.save' ); ?></button>
          </div>
          <p><?php echo $kp_t( 'd.plan.no_double', array( 'date' => $kp_date( $kp_sub->current_period_end ) ) ); ?></p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php /* ---------- What's included ---------- */ ?>
  <?php if ( $kp_benefits ) :
      $kp_fmt = function ( $key, $v ) use ( $kp_t ) {
          switch ( $key ) {
              case 'voice_minutes':
                  return $kp_t( 'd.plan.v_min', array( 'n' => (int) $v ) );
              case 'memory_messages':
                  return $kp_t( 'd.plan.v_last', array( 'n' => (int) $v ) );
              case 'recurring_patterns':
                  return $v ? '<i class="ti ti-check"></i>' : '<span class="no">—</span>';
              case 'reflections_per_month':
                  return $v ? $kp_t( 'd.plan.v_month', array( 'n' => (int) $v ) ) : $kp_t( 'd.plan.v_unlimited' );
              case 'auto_reflection_days':
                  return $v ? $kp_t( 'd.plan.v_every_days', array( 'n' => (int) $v ) ) : $kp_t( 'd.plan.v_off' );
              case 'growth_plans':
                  return $v ? $kp_t( 'd.plan.v_plans', array( 'n' => (int) $v ) ) : $kp_t( 'd.plan.v_unlimited' );
              case 'booking_discount':
                  return (float) $v > 0 ? $kp_t( 'd.plan.v_pct_off', array( 'pct' => kd_pct( $v ) ) ) : '<span class="no">—</span>';
          }
          return esc_html( $v );
      };
      $kp_rows = array(
          'always'                => kounselia_t( 'd.plan.row_always', array(), $dash_lang ),
          'voice_minutes'         => kounselia_t( 'd.plan.row_voice', array(), $dash_lang ),
          'memory_messages'       => kounselia_t( 'd.plan.row_memory', array(), $dash_lang ),
          'recurring_patterns'    => kounselia_t( 'd.plan.row_patterns', array(), $dash_lang ),
          'reflections_per_month' => kounselia_t( 'd.plan.row_reflections', array(), $dash_lang ),
          'auto_reflection_days'  => kounselia_t( 'd.plan.row_auto_refl', array(), $dash_lang ),
          'growth_plans'          => kounselia_t( 'd.plan.row_growth', array(), $dash_lang ),
          'booking_discount'      => kounselia_t( 'd.plan.row_booking', array(), $dash_lang ),
          'safety'                => kounselia_t( 'd.plan.row_safety', array(), $dash_lang ),
      );
      ?>
    <section class="section">
      <div class="section-head"><h2><?php echo $kp_t( 'd.plan.incl_title' ); ?></h2><span class="section-sub"><?php echo $kp_t( 'd.plan.incl_sub' ); ?></span></div>
      <div class="compare">
        <div class="compare-row compare-head">
          <div></div>
          <div class="<?php echo ! $kp_is_pro ? 'you' : ''; ?>"><?php echo $kp_t( 'd.plan.free' ); ?><?php echo ! $kp_is_pro ? '<small>' . $kp_t( 'd.plan.you' ) . '</small>' : ''; ?></div>
          <div class="<?php echo $kp_is_pro ? 'you' : ''; ?> pro">Pro<?php echo $kp_is_pro ? '<small>' . $kp_t( 'd.plan.you' ) . '</small>' : ''; ?></div>
        </div>
        <?php foreach ( $kp_rows as $kp_key => $kp_label ) : ?>
          <div class="compare-row">
            <div class="compare-label"><?php echo esc_html( $kp_label ); ?></div>
            <?php foreach ( array( 'free', 'pro' ) as $kp_tier ) : ?>
              <div class="<?php echo ( 'pro' === $kp_tier ) === $kp_is_pro ? 'you' : ''; ?>">
                <?php echo in_array( $kp_key, array( 'always', 'safety' ), true ) ? '<i class="ti ti-check"></i>' : $kp_fmt( $kp_key, $kp_benefits[ $kp_tier ][ $kp_key ] ); // Built from numbers + fixed markup. ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ( $kp_refl && $kp_refl['limit'] ) : ?>
        <p class="usage-note"><i class="ti ti-bulb"></i> <?php echo $kp_tr( 'd.plan.usage_note', array( 'used' => '<b>' . $kp_t( 'd.plan.used_of', array( 'used' => (int) $kp_refl['used'], 'limit' => (int) $kp_refl['limit'] ) ) . '</b>' ) ); ?></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php /* ---------- Plans ---------- */ ?>
  <?php if ( ! $kp_live && $available_plans ) : ?>
    <section class="section">
      <div class="section-head"><h2><?php echo $kp_t( 'ended' === $kp_state ? 'd.plan.come_back' : 'd.plan.choose' ); ?></h2><span class="section-sub"><?php echo $kp_t( 'd.plan.secure' ); ?></span></div>
      <div class="plans-grid">
        <?php foreach ( $available_plans as $plan ) : ?>
          <div class="plan-card pro">
            <?php if ( ! empty( $plan['is_popular'] ) ) : ?><span class="plan-badge"><?php echo $kp_t( 'd.plan.popular' ); ?></span><?php endif; ?>
            <h3><?php echo esc_html( $plan['name'] ); ?></h3>
            <p class="plan-price"><?php echo $kp_t( 'yearly' === $plan['interval'] ? 'd.plan.price_year' : 'd.plan.price_month', array( 'price' => $kp_money( $kp_price( $plan, $kp_viewer_currency ), $kp_viewer_currency ) ) ); ?></p>
            <ul class="plan-list">
              <?php foreach ( (array) $plan['features'] as $feature ) : ?>
                <li><i class="ti ti-check"></i> <?php echo esc_html( $feature ); ?></li>
              <?php endforeach; ?>
            </ul>
            <button class="btn-plan primary plan-subscribe-btn" data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>"><?php echo $kp_gifted ? $kp_t( 'd.plan.subscribe_anyway' ) : $kp_t( 'd.plan.get', array( 'name' => $plan['name'] ) ); ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="usage-note"><i class="ti ti-lock"></i> <?php echo $kp_t( 'd.plan.paystack_note' ); ?></p>
    </section>
  <?php endif; ?>

  <?php /* ---------- Billing history ---------- */ ?>
  <?php if ( $kp_history ) : ?>
    <section class="section">
      <div class="section-head"><h2><?php echo $kp_t( 'd.plan.billing_history' ); ?></h2></div>
      <div class="bill-list">
        <?php foreach ( $kp_history as $kp_pay ) :
            $kp_p = function_exists( 'kounselia_get_plan' ) ? kounselia_get_plan( $kp_pay->plan_id ) : null; ?>
          <div class="bill-row">
            <div>
              <div class="bill-title"><?php $kp_bill_name = $kp_p ? $kp_p['name'] : ucfirst( str_replace( '-', ' ', $kp_pay->plan_id ) ); echo false !== strpos( $kp_pay->reference, '-RENEW-' ) ? $kp_t( 'd.plan.bill_renewal', array( 'plan' => $kp_bill_name ) ) : esc_html( $kp_bill_name ); ?></div>
              <div class="bill-sub"><?php echo $kp_t( 'd.plan.bill_sub', array( 'date' => kd_date( strtotime( $kp_pay->created_at ), 'M j, Y', 'd MMM y', $dash_lang ), 'ref' => substr( $kp_pay->reference, -10 ) ) ); ?></div>
            </div>
            <div class="bill-amt"><?php echo esc_html( $kp_money( $kp_pay->amount, $kp_pay->currency ) ); ?><span class="bill-status <?php echo esc_attr( $kp_pay->status ); ?>"><?php echo $kp_t( 'success' === $kp_pay->status ? 'd.plan.paid' : 'd.plan.failed' ); ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div><!-- /view-upgrade -->

<?php if ( $kp_public_key ) : ?>
<script src="https://js.paystack.co/v2/inline.js" async></script>
<?php endif; ?>
<script>
(function(){
  function post(data){
    return fetch(KOUNSELIA.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ nonce: KOUNSELIA.nonce }, data)) }).then(function(r){ return r.json(); });
  }
  function reset(btn, text){ btn.disabled = false; btn.textContent = text; }

  // Subscribe / pay: pop-up checkout when a Paystack public key is set, otherwise Paystack's page.
  document.querySelectorAll('.plan-subscribe-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var text = btn.textContent, planId = btn.dataset.planId;
      btn.disabled = true; btn.textContent = tr('d.plan.starting');
      function start(forceRedirect){
        return post({ action: 'kounselia_init_subscription_payment', plan_id: planId, redirect: forceRedirect ? 1 : 0 }).then(function(res){
          if(!res.success){ throw new Error((res.data && res.data.message) || tr('d.plan.start_fail')); }
          var d = res.data;
          if(d.authorization_url){ window.location.href = d.authorization_url; return; }
          if(d.mode === 'inline'){
            if(!window.PaystackPop){ return start(true); } // Pop-up script blocked or offline.
            new window.PaystackPop().newTransaction({
              key: d.key, email: d.email, amount: d.amount, currency: d.currency, reference: d.reference, metadata: d.metadata,
              onSuccess: function(tx){ btn.textContent = tr('d.plan.confirming'); window.location.href = d.callback_url + '?reference=' + encodeURIComponent((tx && tx.reference) || d.reference); },
              onCancel: function(){ reset(btn, text); toast(tr('d.plan.checkout_closed')); },
              onError: function(){ reset(btn, text); toast(tr('d.plan.checkout_error'), true); }
            });
          }
        });
      }
      start(false).catch(function(e){ reset(btn, text); toast(e.message || tr('d.plan.start_fail_short'), true); });
    });
  });

  // Turn off auto-renew.
  var cancelBtn = document.getElementById('sub-cancel-btn');
  if(cancelBtn){
    cancelBtn.addEventListener('click', function(){
      if(!confirm(tr('d.plan.cancel_confirm'))) return;
      cancelBtn.disabled = true;
      post({ action: 'kounselia_cancel_subscription' }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : (res.success ? tr('d.plan.auto_off') : tr('d.plan.update_fail')), !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1100); } else { cancelBtn.disabled = false; }
      }).catch(function(){ cancelBtn.disabled = false; toast(tr('d.err.conn_problem'), true); });
    });
  }

  // Resume auto-renew, remove card.
  document.querySelectorAll('[data-sub-action]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if(btn.dataset.confirm && !confirm(btn.dataset.confirm)) return;
      btn.disabled = true;
      post({ action: btn.dataset.subAction }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : tr('d.common.updated'), !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1200); } else { btn.disabled = false; }
      }).catch(function(){ btn.disabled = false; toast(tr('d.err.conn_problem'), true); });
    });
  });

  // Switch plan from the next renewal.
  var switchBtn = document.getElementById('switch-plan-btn');
  if(switchBtn){
    switchBtn.addEventListener('click', function(){
      switchBtn.disabled = true;
      post({ action: 'kounselia_switch_subscription_plan', plan_id: document.getElementById('switch-plan').value }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : tr('d.common.updated'), !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1600); } else { switchBtn.disabled = false; }
      }).catch(function(){ switchBtn.disabled = false; toast(tr('d.err.conn_problem'), true); });
    });
  }
})();
</script>
