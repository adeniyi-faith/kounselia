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
$kp_date     = function ( $mysql ) {
    return date_i18n( 'F j, Y', strtotime( $mysql ) );
};
$kp_public_key = function_exists( 'kounselia_paystack_public_key' ) ? kounselia_paystack_public_key() : '';
?>
<div class="view-panel" id="view-upgrade">
  <section class="section">
    <div class="section-head"><h2>My plan</h2><span class="section-sub"><?php echo $kp_is_pro ? 'Thank you for supporting Kounselia' : 'Free forever, upgrade any time'; ?></span></div>

    <?php /* ---------- Where you stand ---------- */ ?>
    <div class="plan-hero state-<?php echo esc_attr( $kp_gifted ? 'gifted' : $kp_state ); ?>">
      <div class="plan-hero-icon"><i class="ti <?php echo esc_attr( array( 'none' => 'ti-leaf', 'active' => 'ti-sparkles', 'renewal_off' => 'ti-clock-pause', 'payment_problem' => 'ti-alert-triangle', 'ended' => 'ti-hourglass-empty' )[ $kp_state ] ?? 'ti-sparkles' ); ?>"></i></div>
      <div class="plan-hero-meta">
        <?php if ( $kp_gifted ) : ?>
          <h3>You have Pro</h3>
          <p>A gift from the Kounselia team — enjoy every Pro benefit below.</p>
        <?php elseif ( 'active' === $kp_state ) : ?>
          <h3>You're on <?php echo esc_html( $kp_sub->plan_name ); ?></h3>
          <?php if ( $kp_summary['auto_renews'] ) : ?>
            <p>Renews automatically on <b><?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?></b> for <?php echo esc_html( $kp_money( $kp_summary['renew_amount'], $kp_summary['currency'] ) ); ?><?php echo $kp_summary['next_plan'] ? ' (moving to ' . esc_html( $kp_summary['next_plan']['name'] ) . ')' : ''; ?>.</p>
          <?php else : ?>
            <p>Your plan runs until <b><?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?></b>. We don't have a card saved to renew it automatically — you can renew then.</p>
          <?php endif; ?>
        <?php elseif ( 'renewal_off' === $kp_state ) : ?>
          <h3><?php echo esc_html( $kp_sub->plan_name ); ?> until <?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?></h3>
          <p>Auto-renew is off. You keep every Pro benefit until then, and won't be charged again.</p>
        <?php elseif ( 'payment_problem' === $kp_state ) : ?>
          <h3>We couldn't renew your plan</h3>
          <p><?php echo $kp_sub->last_renewal_error ? esc_html( $kp_sub->last_renewal_error ) . '. ' : ''; ?><?php echo (int) $kp_sub->renewal_attempts < KOUNSELIA_RENEWAL_MAX_ATTEMPTS ? "We'll try your card again automatically, or you can pay now with another card." : 'Pay now with any card to keep Pro.'; ?> Pro stays on until <?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?>.</p>
        <?php elseif ( 'ended' === $kp_state ) : ?>
          <h3>Your <?php echo esc_html( $kp_sub->plan_name ); ?> plan ended</h3>
          <p>It ended on <?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?>. You're on Free now — pick a plan below whenever you're ready.</p>
        <?php else : ?>
          <h3>You're on Free</h3>
          <p>Everything you need to talk, reflect and track your mood — free forever. Pro adds deeper memory, longer voice calls and more.</p>
        <?php endif; ?>
      </div>
      <div class="plan-hero-actions">
        <?php if ( 'active' === $kp_state && $kp_summary['auto_renews'] ) : ?>
          <button class="sub-cancel-btn" id="sub-cancel-btn">Turn off auto-renew</button>
        <?php elseif ( 'renewal_off' === $kp_state && $kp_summary['has_card'] ) : ?>
          <button class="btn-plan primary" data-sub-action="kounselia_resume_subscription">Turn auto-renew back on</button>
        <?php elseif ( 'payment_problem' === $kp_state ) : ?>
          <button class="btn-plan primary plan-subscribe-btn" data-plan-id="<?php echo esc_attr( $kp_sub->pending_plan_id ? $kp_sub->pending_plan_id : $kp_sub->plan_id ); ?>">Pay now</button>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ---------- Card & next payment ---------- */ ?>
    <?php if ( $kp_live ) : ?>
      <div class="billing-grid">
        <div class="billing-box">
          <div class="billing-label">Payment method</div>
          <?php if ( $kp_summary['has_card'] ) : ?>
            <div class="billing-card"><i class="ti ti-credit-card"></i> <?php echo esc_html( trim( ( $kp_sub->card_brand ? $kp_sub->card_brand : 'Card' ) . ' •••• ' . $kp_sub->card_last4 ) ); ?><?php echo $kp_sub->card_exp ? '<span>Expires ' . esc_html( $kp_sub->card_exp ) . '</span>' : ''; ?></div>
            <button class="link-btn" data-sub-action="kounselia_remove_subscription_card" data-confirm="Remove this card? Auto-renew will be turned off, and you'll keep Pro until your current period ends.">Remove card</button>
          <?php else : ?>
            <div class="billing-card muted"><i class="ti ti-credit-card-off"></i> No card saved</div>
          <?php endif; ?>
        </div>
        <div class="billing-box">
          <div class="billing-label">Next payment</div>
          <?php if ( $kp_summary['auto_renews'] ) : ?>
            <div class="billing-big"><?php echo esc_html( $kp_money( $kp_summary['renew_amount'], $kp_summary['currency'] ) ); ?></div>
            <div class="billing-sub">on <?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?></div>
          <?php else : ?>
            <div class="billing-big muted">None scheduled</div>
            <div class="billing-sub">You won't be charged again.</div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ( count( $available_plans ) > 1 && in_array( $kp_state, array( 'active', 'payment_problem' ), true ) ) : ?>
        <div class="switch-box">
          <label for="switch-plan">Change plan from your next renewal</label>
          <div class="switch-row">
            <select id="switch-plan">
              <?php foreach ( $available_plans as $kp_plan ) : ?>
                <option value="<?php echo esc_attr( $kp_plan['id'] ); ?>" <?php selected( $kp_sub->pending_plan_id ? $kp_sub->pending_plan_id : $kp_sub->plan_id, $kp_plan['id'] ); ?>><?php echo esc_html( $kp_plan['name'] . ' — ' . $kp_money( $kp_plan['price_amount'], $kp_plan['currency'] ) . ' / ' . ( 'yearly' === $kp_plan['interval'] ? 'year' : 'month' ) ); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn-plan" id="switch-plan-btn">Save</button>
          </div>
          <p>No double charge: you keep your current plan until <?php echo esc_html( $kp_date( $kp_sub->current_period_end ) ); ?>, then renew on the new one.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php /* ---------- What's included ---------- */ ?>
  <?php if ( $kp_benefits ) :
      $kp_fmt = function ( $key, $v ) {
          switch ( $key ) {
              case 'voice_minutes':
                  return (int) $v . ' min';
              case 'memory_messages':
                  return 'Last ' . (int) $v . ' messages';
              case 'recurring_patterns':
                  return $v ? '<i class="ti ti-check"></i>' : '<span class="no">—</span>';
              case 'reflections_per_month':
                  return $v ? (int) $v . ' / month' : 'Unlimited';
              case 'auto_reflection_days':
                  return $v ? 'Every ' . (int) $v . ' days' : 'Off';
              case 'booking_discount':
                  return (float) $v > 0 ? rtrim( rtrim( number_format( (float) $v, 1 ), '0' ), '.' ) . '% off' : '<span class="no">—</span>';
          }
          return esc_html( $v );
      };
      $kp_rows = array(
          'always'                => 'Unlimited private conversations',
          'voice_minutes'         => 'Voice calls with your counselor',
          'memory_messages'       => 'How much of each conversation they keep in mind',
          'recurring_patterns'    => 'Notices your recurring patterns across sessions',
          'reflections_per_month' => 'Milestone Reflections',
          'auto_reflection_days'  => 'Reflection refreshes itself',
          'booking_discount'      => 'Sessions with licensed professionals',
          'safety'                => 'Safety support and crisis resources',
      );
      ?>
    <section class="section">
      <div class="section-head"><h2>What's included</h2><span class="section-sub">The real difference between plans</span></div>
      <div class="compare">
        <div class="compare-row compare-head">
          <div></div>
          <div class="<?php echo ! $kp_is_pro ? 'you' : ''; ?>">Free<?php echo ! $kp_is_pro ? '<small>You</small>' : ''; ?></div>
          <div class="<?php echo $kp_is_pro ? 'you' : ''; ?> pro">Pro<?php echo $kp_is_pro ? '<small>You</small>' : ''; ?></div>
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
        <p class="usage-note"><i class="ti ti-bulb"></i> Milestone Reflections this month: <b><?php echo (int) $kp_refl['used']; ?> of <?php echo (int) $kp_refl['limit']; ?></b> used. Resets on the 1st.</p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php /* ---------- Plans ---------- */ ?>
  <?php if ( ! $kp_live && $available_plans ) : ?>
    <section class="section">
      <div class="section-head"><h2><?php echo 'ended' === $kp_state ? 'Come back to Pro' : 'Choose a plan'; ?></h2><span class="section-sub">Secure payment by Paystack · cancel any time</span></div>
      <div class="plans-grid">
        <?php foreach ( $available_plans as $plan ) : ?>
          <div class="plan-card pro">
            <?php if ( ! empty( $plan['is_popular'] ) ) : ?><span class="plan-badge">Popular</span><?php endif; ?>
            <h3><?php echo esc_html( $plan['name'] ); ?></h3>
            <p class="plan-price"><?php echo esc_html( $kp_money( $plan['price_amount'], $plan['currency'] ) ); ?> / <?php echo 'yearly' === $plan['interval'] ? 'year' : 'month'; ?></p>
            <ul class="plan-list">
              <?php foreach ( (array) $plan['features'] as $feature ) : ?>
                <li><i class="ti ti-check"></i> <?php echo esc_html( $feature ); ?></li>
              <?php endforeach; ?>
            </ul>
            <button class="btn-plan primary plan-subscribe-btn" data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>"><?php echo $kp_gifted ? 'Subscribe anyway' : 'Get ' . esc_html( $plan['name'] ); ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="usage-note"><i class="ti ti-lock"></i> Your card is handled by Paystack — Kounselia never sees or stores your card number. Your plan renews automatically; turn that off any time here.</p>
    </section>
  <?php endif; ?>

  <?php /* ---------- Billing history ---------- */ ?>
  <?php if ( $kp_history ) : ?>
    <section class="section">
      <div class="section-head"><h2>Billing history</h2></div>
      <div class="bill-list">
        <?php foreach ( $kp_history as $kp_pay ) :
            $kp_p = function_exists( 'kounselia_get_plan' ) ? kounselia_get_plan( $kp_pay->plan_id ) : null; ?>
          <div class="bill-row">
            <div>
              <div class="bill-title"><?php echo esc_html( $kp_p ? $kp_p['name'] : ucfirst( str_replace( '-', ' ', $kp_pay->plan_id ) ) ); ?><?php echo false !== strpos( $kp_pay->reference, '-RENEW-' ) ? ' · renewal' : ''; ?></div>
              <div class="bill-sub"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $kp_pay->created_at ) ) ); ?> · Ref <?php echo esc_html( substr( $kp_pay->reference, -10 ) ); ?></div>
            </div>
            <div class="bill-amt"><?php echo esc_html( $kp_money( $kp_pay->amount, $kp_pay->currency ) ); ?><span class="bill-status <?php echo esc_attr( $kp_pay->status ); ?>"><?php echo 'success' === $kp_pay->status ? 'Paid' : 'Failed'; ?></span></div>
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
      btn.disabled = true; btn.textContent = 'Starting checkout…';
      function start(forceRedirect){
        return post({ action: 'kounselia_init_subscription_payment', plan_id: planId, redirect: forceRedirect ? 1 : 0 }).then(function(res){
          if(!res.success){ throw new Error((res.data && res.data.message) || 'Could not start checkout, please try again.'); }
          var d = res.data;
          if(d.authorization_url){ window.location.href = d.authorization_url; return; }
          if(d.mode === 'inline'){
            if(!window.PaystackPop){ return start(true); } // Pop-up script blocked or offline.
            new window.PaystackPop().newTransaction({
              key: d.key, email: d.email, amount: d.amount, currency: d.currency, reference: d.reference, metadata: d.metadata,
              onSuccess: function(tx){ btn.textContent = 'Confirming…'; window.location.href = d.callback_url + '?reference=' + encodeURIComponent((tx && tx.reference) || d.reference); },
              onCancel: function(){ reset(btn, text); toast('Checkout closed — you have not been charged.'); },
              onError: function(){ reset(btn, text); toast('Checkout could not open. Please try again.', true); }
            });
          }
        });
      }
      start(false).catch(function(e){ reset(btn, text); toast(e.message || 'Could not start checkout.', true); });
    });
  });

  // Turn off auto-renew.
  var cancelBtn = document.getElementById('sub-cancel-btn');
  if(cancelBtn){
    cancelBtn.addEventListener('click', function(){
      if(!confirm('Turn off auto-renew? You keep Pro until the end of the period you have paid for, and won\'t be charged again.')) return;
      cancelBtn.disabled = true;
      post({ action: 'kounselia_cancel_subscription' }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : (res.success ? 'Auto-renew turned off.' : 'Could not update your plan.'), !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1100); } else { cancelBtn.disabled = false; }
      }).catch(function(){ cancelBtn.disabled = false; toast('Connection problem, please try again.', true); });
    });
  }

  // Resume auto-renew, remove card.
  document.querySelectorAll('[data-sub-action]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if(btn.dataset.confirm && !confirm(btn.dataset.confirm)) return;
      btn.disabled = true;
      post({ action: btn.dataset.subAction }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : 'Updated.', !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1200); } else { btn.disabled = false; }
      }).catch(function(){ btn.disabled = false; toast('Connection problem, please try again.', true); });
    });
  });

  // Switch plan from the next renewal.
  var switchBtn = document.getElementById('switch-plan-btn');
  if(switchBtn){
    switchBtn.addEventListener('click', function(){
      switchBtn.disabled = true;
      post({ action: 'kounselia_switch_subscription_plan', plan_id: document.getElementById('switch-plan').value }).then(function(res){
        toast(res.data && res.data.message ? res.data.message : 'Updated.', !res.success);
        if(res.success){ setTimeout(function(){ location.reload(); }, 1600); } else { switchBtn.disabled = false; }
      }).catch(function(){ switchBtn.disabled = false; toast('Connection problem, please try again.', true); });
    });
  }
})();
</script>
