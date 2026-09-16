<?php
/**
 * Shared schedule for recurring maintenance: how often, and at what hour.
 *
 * Written for the page cache cleanup, and meant to be reused by every other
 * recurring task of the plugin instead of each one scheduling itself on its own
 * terms. The one it replaces programmed the event for "one hour after the
 * module was switched on", twice a day, so the hour a site cleaned up was
 * whatever time somebody happened to click the toggle, and the only way to move
 * it was to switch the cache off and on again, which empties it.
 *
 * Three things shape it:
 *
 * - The hour is the site's, not the server's. wp_schedule_event() takes a UTC
 *   timestamp, so the chosen hour is converted with the site time zone, and the
 *   event is moved back to its hour whenever it drifts off it, which is what a
 *   daylight saving change or a new time zone setting does.
 * - It is only rescheduled when it no longer matches. Rescheduling on every
 *   save of the settings would let an administrator who saves often push the
 *   next run forward indefinitely, so it would never run.
 * - The minute is spread per site. With a chosen hour, every site on a shared
 *   server that picked "4:00" would otherwise clean up in the same minute,
 *   because wp_reschedule_event() keeps the phase of the event
 *   (wp-includes/cron.php:463).
 *
 * WP-Cron is not a real cron: an event fires with the first visit that WordPress
 * builds after its time, so on a quiet site "4:00" means "the first visit after
 * 4:00". The settings screen says so next to the control.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Schedule {

	/** @var int Hour value meaning "no fixed hour". */
	const AUTO_HOUR = -1;

	/**
	 * Frequencies on offer, by WordPress schedule name.
	 *
	 * Only the schedules WordPress itself registers: anything shorter than an
	 * hour needs a cron_schedules entry of its own, and with it a "chosen hour"
	 * stops meaning anything.
	 *
	 * @return array Schedule name => label.
	 */
	public static function get_frequencies() {
		return array(
			'hourly'     => __( 'Every hour', 'wpo-tweaks' ),
			'twicedaily' => __( 'Twice a day', 'wpo-tweaks' ),
			'daily'      => __( 'Once a day', 'wpo-tweaks' ),
		);
	}

	/**
	 * A frequency from the list, or the fallback.
	 *
	 * @param mixed  $value    Submitted value.
	 * @param string $fallback Value to use when it is not one on offer.
	 * @return string
	 */
	public static function sanitize_frequency( $value, $fallback = 'twicedaily' ) {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::get_frequencies() ) ? $value : $fallback;
	}

	/**
	 * An hour between 0 and 23, or AUTO_HOUR.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_hour( $value ) {
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return self::AUTO_HOUR;
		}
		if ( ! is_numeric( $value ) ) {
			return self::AUTO_HOUR;
		}

		$hour = (int) $value;

		return ( $hour >= 0 && $hour <= 23 ) ? $hour : self::AUTO_HOUR;
	}

	/**
	 * Make sure the event exists and still runs when it was asked to.
	 *
	 * An event that is overdue counts as scheduled: it is WP-Cron that is late,
	 * and adding a second one would only run the task twice once it catches up.
	 *
	 * @param string $hook      Cron hook.
	 * @param string $frequency Schedule name.
	 * @param int    $hour      Hour of the site, or AUTO_HOUR.
	 * @return bool Whether the event was (re)scheduled on this call.
	 */
	public static function ensure( $hook, $frequency, $hour ) {
		$frequency = self::sanitize_frequency( $frequency );
		$hour      = self::sanitize_hour( $hour );
		$event     = wp_get_scheduled_event( $hook );

		if ( ! $event ) {
			wp_schedule_event( self::first_run( $frequency, $hour ), $frequency, $hook );
			return true;
		}

		if ( ! self::matches( $event, $frequency, $hour ) ) {
			// Once an hour at most. ensure() runs on every request, and if the
			// event and its expected hour ever disagree again for a reason not
			// foreseen here, rescheduling each time would rewrite the cron option
			// on every page load, which loses the entries other plugins save.
			$guard = 'core_diet_schedule_moved_' . $hook;
			if ( get_transient( $guard ) ) {
				return false;
			}
			set_transient( $guard, 1, HOUR_IN_SECONDS );

			self::reschedule( $hook, $frequency, $hour );
			return true;
		}

		return false;
	}

	/**
	 * Replace the event with one that follows the given schedule.
	 *
	 * @param string $hook      Cron hook.
	 * @param string $frequency Schedule name.
	 * @param int    $hour      Hour of the site, or AUTO_HOUR.
	 */
	public static function reschedule( $hook, $frequency, $hour ) {
		$frequency = self::sanitize_frequency( $frequency );
		$hour      = self::sanitize_hour( $hour );

		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( self::first_run( $frequency, $hour ), $frequency, $hook );
	}

	/**
	 * Whether a scheduled event already follows the given schedule.
	 *
	 * With no fixed hour any time is right, as before: the event runs wherever
	 * it happened to be placed. With one, the event has to fall inside that
	 * hour of the site, or twelve hours later when it runs twice a day.
	 *
	 * @param object $event     Event as wp_get_scheduled_event() returns it.
	 * @param string $frequency Schedule name.
	 * @param int    $hour      Hour of the site, or AUTO_HOUR.
	 * @return bool
	 */
	public static function matches( $event, $frequency, $hour ) {
		if ( ! is_object( $event ) || ! isset( $event->schedule, $event->timestamp ) ) {
			return false;
		}
		if ( $event->schedule !== $frequency ) {
			return false;
		}
		if ( self::AUTO_HOUR === $hour || 'hourly' === $frequency ) {
			return true;
		}

		$local = (int) wp_date( 'G', (int) $event->timestamp );

		return in_array( $local, self::slots( $frequency, $hour ), true );
	}

	/**
	 * Timestamp of the first run.
	 *
	 * @param string   $frequency Schedule name.
	 * @param int      $hour      Hour of the site, or AUTO_HOUR.
	 * @param int|null $now       Current time, for tests.
	 * @return int
	 */
	public static function first_run( $frequency, $hour, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		// No fixed hour: an hour from now, which is what the cleanup has always
		// done, so a site that never touches the setting sees no change at all.
		if ( self::AUTO_HOUR === $hour || 'hourly' === $frequency ) {
			return $now + HOUR_IN_SECONDS;
		}

		$zone  = wp_timezone();
		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$best  = 0;

		// Today and the next days, until a slot at its own hour turns up. The day
		// the clock jumps forward, the chosen hour does not exist (2:00 in most
		// of Europe and the US): setTime() moves it to 3:00, matches() rejects
		// that, and ensure() used to reschedule on every request for a day and a
		// half, two writes of the cron option each time.
		for ( $day = 0; $day <= 3 && ! $best; $day++ ) {
			foreach ( self::slots( $frequency, $hour ) as $slot ) {
				$candidate = $today->modify( '+' . $day . ' day' )->setTime( $slot, self::minute_offset() );
				if ( (int) $candidate->format( 'G' ) !== $slot || $candidate->getTimestamp() <= $now ) {
					continue;
				}
				$timestamp = $candidate->getTimestamp();
				if ( ! $best || $timestamp < $best ) {
					$best = $timestamp;
				}
			}
		}

		return $best ? $best : $now + HOUR_IN_SECONDS;
	}

	/**
	 * Hours of the site the task runs at.
	 *
	 * @param string $frequency Schedule name.
	 * @param int    $hour      Chosen hour.
	 * @return int[]
	 */
	private static function slots( $frequency, $hour ) {
		return 'twicedaily' === $frequency ? array( $hour, ( $hour + 12 ) % 24 ) : array( $hour );
	}

	/**
	 * Minute within the chosen hour, the same every time for a given site.
	 *
	 * @return int 0 to 59.
	 */
	public static function minute_offset() {
		return (int) ( abs( crc32( (string) home_url() ) ) % 60 );
	}
}
