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
      $kounselia_when     = 0 === $kounselia_days ? 'Today' : ( 1 === $kounselia_days ? 'Tomorrow' : date_i18n( 'l', $kounselia_start ) );
      $kounselia_can_join = function_exists( 'kounselia_booking_is_joinable' ) && kounselia_booking_is_joinable( $kounselia_next_booking );
      ?>
    <div class="care-date">
      <span class="care-date-m"><?php echo esc_html( date_i18n( 'M', $kounselia_start ) ); ?></span>
      <span class="care-date-d"><?php echo esc_html( date_i18n( 'j', $kounselia_start ) ); ?></span>
    </div>
    <div class="care-meta">
      <div class="care-eyebrow"><i class="ti ti-calendar-event"></i> Your next session</div>
      <h3><?php echo esc_html( $kounselia_next_booking->pro_name ); ?></h3>
      <p><?php echo esc_html( $kounselia_when . ' · ' . date_i18n( 'g:i A', $kounselia_start ) ); ?><?php echo $kounselia_next_booking->pro_title ? ' · ' . esc_html( $kounselia_next_booking->pro_title ) : ''; ?><?php echo count( $my_bookings ) > 1 ? ' · +' . ( count( $my_bookings ) - 1 ) . ' more booked' : ''; ?></p>
    </div>
    <div class="care-actions">
      <?php if ( $kounselia_can_join ) : ?>
        <a class="btn-rec" href="/video-call.php?booking_id=<?php echo (int) $kounselia_next_booking->id; ?>"><i class="ti ti-video"></i> Join now</a>
      <?php endif; ?>
      <button type="button" class="btn-rec <?php echo $kounselia_can_join ? 'secondary' : ''; ?>" onclick="switchTab('professionals')">Manage</button>
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
      <div class="care-eyebrow"><i class="ti ti-user-heart"></i> Your care team</div>
      <h3>Sometimes you want a person in the room</h3>
      <p>Book a video session with a licensed, verified professional — on your schedule.<?php if ( ! empty( $kounselia_session_discount ) ) : ?> <b class="care-perk">You save <?php echo esc_html( rtrim( rtrim( number_format( (float) $kounselia_session_discount, 1 ), '0' ), '.' ) ); ?>% with Pro.</b><?php endif; ?></p>
    </div>
    <div class="care-actions">
      <button type="button" class="btn-rec" onclick="switchTab('professionals')"><?php echo empty( $verified_professionals ) ? 'See professionals' : 'Browse professionals'; ?></button>
    </div>
  <?php endif; ?>
</section>
