<?php
/**
 * Member dashboard — "Your care team" card on the Home tab.
 *
 * Booking a licensed professional used to be a tab in the phone's
 * bottom bar, squeezed between everyday actions. It lives here instead,
 * where it's useful: your next session front and centre (with Join /
 * Message), or — if nothing is booked — a gentle invitation showing
 * real professionals you can book. The full booking screen is still one
 * tap away (switchTab('professionals')).
 *
 * Expects from dashboard.php: $my_bookings, $verified_professionals,
 * $kounselia_session_discount.
 */
$kounselia_next_booking = ! empty( $my_bookings ) ? $my_bookings[0] : null;
?>
<section class="care-card <?php echo $kounselia_next_booking ? 'has-next' : ''; ?>">
  <?php if ( $kounselia_next_booking ) :
      $kounselia_start    = strtotime( $kounselia_next_booking->scheduled_start );
      $kounselia_days     = (int) floor( ( strtotime( date( 'Y-m-d', $kounselia_start ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
      $kounselia_when     = 0 === $kounselia_days ? kounselia_t( 'd.care.today', array(), $dash_lang ) : ( 1 === $kounselia_days ? kounselia_t( 'd.care.tomorrow', array(), $dash_lang ) : kd_date( $kounselia_start, 'l', 'EEEE', $dash_lang ) );
      $kounselia_can_join = function_exists( 'kounselia_booking_is_joinable' ) && kounselia_booking_is_joinable( $kounselia_next_booking );
      ?>
    <div class="care-date">
      <span class="care-date-m"><?php echo esc_html( kd_date( $kounselia_start, 'M', 'MMM', $dash_lang ) ); ?></span>
      <span class="care-date-d"><?php echo esc_html( kd_date( $kounselia_start, 'j', 'd', $dash_lang ) ); ?></span>
    </div>
    <div class="care-meta">
      <div class="care-eyebrow"><i class="ti ti-calendar-event"></i> <?php echo esc_html( kounselia_t( 'd.care.next', array(), $dash_lang ) ); ?></div>
      <h3><?php echo esc_html( $kounselia_next_booking->pro_name ); ?></h3>
      <p><?php echo esc_html( $kounselia_when . ' · ' . kd_date( $kounselia_start, 'g:i A', 'HH:mm', $dash_lang ) ); ?><?php echo $kounselia_next_booking->pro_title ? ' · ' . esc_html( $kounselia_next_booking->pro_title ) : ''; ?><?php echo count( $my_bookings ) > 1 ? ' · ' . esc_html( kounselia_t( 'd.care.more_booked', array('n' => count( $my_bookings ) - 1), $dash_lang ) ) : ''; ?></p>
    </div>
    <div class="care-actions">
      <?php if ( $kounselia_can_join ) : ?>
        <a class="btn-rec" href="/video-call.php?booking_id=<?php echo (int) $kounselia_next_booking->id; ?>"><i class="ti ti-video"></i> <?php echo esc_html( kounselia_t( 'd.care.join_now', array(), $dash_lang ) ); ?></a>
      <?php endif; ?>
      <button type="button" class="btn-rec <?php echo $kounselia_can_join ? 'secondary' : ''; ?>" onclick="switchTab('professionals')"><?php echo esc_html( kounselia_t( 'd.care.manage', array(), $dash_lang ) ); ?></button>
    </div>
  <?php else : ?>
    <div class="care-stack" aria-hidden="true">
      <?php
      $kounselia_shown = 0;
      foreach ( array_slice( (array) $verified_professionals, 0, 3 ) as $kounselia_pro ) :
          $kounselia_av = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $kounselia_pro->user_id, 'thumbnail' ) : false;
          $kounselia_shown++;
          ?>
        <span class="care-av"><?php echo $kounselia_av ? '<img src="' . esc_url( $kounselia_av ) . '" alt="">' : esc_html( mb_strtoupper( mb_substr( $kounselia_pro->display_name, 0, 1 ) ) ); ?></span>
      <?php endforeach; ?>
      <?php if ( ! $kounselia_shown ) : ?><span class="care-av icon"><i class="ti ti-stethoscope"></i></span><?php endif; ?>
    </div>
    <div class="care-meta">
      <div class="care-eyebrow"><i class="ti ti-user-heart"></i> <?php echo esc_html( kounselia_t( 'd.care.team', array(), $dash_lang ) ); ?></div>
      <h3><?php echo esc_html( kounselia_t( 'd.care.headline', array(), $dash_lang ) ); ?></h3>
      <p><?php echo esc_html( kounselia_t( 'd.care.body', array(), $dash_lang ) ); ?><?php if ( ! empty( $kounselia_session_discount ) ) : ?> <b class="care-perk"><?php echo esc_html( kounselia_t( 'd.care.save', array('pct' => kd_pct( $kounselia_session_discount )), $dash_lang ) ); ?></b><?php endif; ?></p>
    </div>
    <div class="care-actions">
      <button type="button" class="btn-rec" onclick="switchTab('professionals')"><?php echo esc_html( kounselia_t( empty( $verified_professionals ) ? 'd.care.see' : 'd.care.browse', array(), $dash_lang ) ); ?></button>
    </div>
  <?php endif; ?>
</section>
