<?php
/**
 * Self-Exclusion: Manage my account — My Account endpoint template.
 *
 * Renders either:
 *  - MODE A: A status panel when the user is currently excluded.
 *  - MODE B: Three forms (pause, suspend, close) when the account is active.
 *
 * Template override: place a copy at <theme>/woocommerce/account-status.php
 * to override in a child theme.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defensive: guard against missing helpers (e.g. plugin deactivated mid-request).
$state = function_exists( 'nera_se_state' )
	? nera_se_state( get_current_user_id() )
	: array( 'status' => '', 'until' => 0, 'active' => false );
?>

<section class="nera-se" id="nera-se-root">

	<?php if ( $state['active'] ) : ?>
		<?php /* ================================================ MODE A — Excluded ================================================ */ ?>

		<?php
		$status_key = $state['status'];
		$label      = function_exists( 'nera_se_status_label' ) ? nera_se_status_label( $status_key ) : $status_key;
		$modifier   = 'nera-se__status--' . esc_attr( $status_key );
		?>

		<div class="nera-se__status <?php echo esc_attr( $modifier ); ?>">
			<div class="nera-se__status-icon" aria-hidden="true">
				<?php if ( 'closed' === $status_key ) : ?>
					<span class="material-symbols-outlined">lock</span>
				<?php elseif ( 'suspended' === $status_key ) : ?>
					<span class="material-symbols-outlined">pause_circle</span>
				<?php else : ?>
					<span class="material-symbols-outlined">bedtime</span>
				<?php endif; ?>
			</div>

			<div class="nera-se__status-body">
				<h2 class="nera-se__status-heading">
					<?php
					printf(
						/* translators: %s: status label e.g. "Paused". */
						esc_html__( 'Account %s', 'nera-self-exclusion' ),
						esc_html( $label )
					);
					?>
				</h2>

				<?php if ( 'closed' === $status_key ) : ?>

					<p class="nera-se__status-message">
						<?php esc_html_e( 'Your account has been permanently closed. This cannot be reversed.', 'nera-self-exclusion' ); ?>
					</p>
					<p class="nera-se__status-help">
						<?php esc_html_e( 'If you believe this was an error, or you need assistance, please contact our support team.', 'nera-self-exclusion' ); ?>
					</p>

				<?php else : ?>

					<?php if ( $state['until'] > 0 ) : ?>
						<p class="nera-se__status-message">
							<?php
							printf(
								/* translators: 1: status label, 2: formatted end date. */
								esc_html__( 'Your account is %1$s until %2$s.', 'nera-self-exclusion' ),
								esc_html( strtolower( $label ) ),
								'<strong>' . esc_html( date_i18n( get_option( 'date_format' ), (int) $state['until'] ) ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>

					<p class="nera-se__status-note">
						<?php esc_html_e( 'This break cannot be ended early. Your account will reactivate automatically once the period has passed.', 'nera-self-exclusion' ); ?>
					</p>

				<?php endif; ?>
			</div>
		</div><!-- /.nera-se__status -->

	<?php else : ?>
		<?php /* ================================================ MODE B — Active (show forms) ================================================ */ ?>

		<div class="nera-se__intro">
			<h2 class="nera-se__intro-heading">
				<?php esc_html_e( 'Responsible gambling tools', 'nera-self-exclusion' ); ?>
			</h2>
			<p class="nera-se__intro-copy">
				<?php esc_html_e( 'If you want to take a break from competitions, you can pause or suspend your account for a set period, or close it permanently. These tools are here to help you stay in control.', 'nera-self-exclusion' ); ?>
			</p>
		</div>

		<?php /* -------- PAUSE FORM -------- */ ?>
		<div class="nera-se__card nera-se__card--pause">
			<div class="nera-se__card-header">
				<span class="material-symbols-outlined nera-se__card-icon" aria-hidden="true">bedtime</span>
				<div>
					<h3 class="nera-se__card-title"><?php esc_html_e( 'Take a short break', 'nera-self-exclusion' ); ?></h3>
					<p class="nera-se__card-subtitle"><?php esc_html_e( 'Pause your account for up to 6 months. It will reactivate automatically.', 'nera-self-exclusion' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( wc_get_account_endpoint_url( 'account-status' ) ); ?>" class="nera-se__form" id="nera-se-pause-form">
				<input type="hidden" name="action" value="nera_account_pause" />
				<?php wp_nonce_field( 'nera_account_pause', 'nera_account_pause_nonce' ); ?>
				<input type="hidden" name="nera_account_user_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" />

				<fieldset class="nera-se__fieldset">
					<legend class="nera-se__legend"><?php esc_html_e( 'How long would you like to pause?', 'nera-self-exclusion' ); ?></legend>

					<div class="nera-se__radio-pills">
						<?php
						$pause_presets = array(
							1   => __( '1 day', 'nera-self-exclusion' ),
							7   => __( '1 week', 'nera-self-exclusion' ),
							30  => __( '1 month', 'nera-self-exclusion' ),
							90  => __( '3 months', 'nera-self-exclusion' ),
							183 => __( '6 months', 'nera-self-exclusion' ),
						);
						foreach ( $pause_presets as $days => $preset_label ) :
							?>
							<label class="nera-se__pill" for="pause-preset-<?php echo esc_attr( $days ); ?>">
								<input
									type="radio"
									id="pause-preset-<?php echo esc_attr( $days ); ?>"
									name="pause_days"
									value="<?php echo esc_attr( $days ); ?>"
									class="nera-se__pill-radio"
									<?php checked( 7, $days ); ?>
								/>
								<span class="nera-se__pill-label"><?php echo esc_html( $preset_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="nera-se__custom-row">
						<label for="pause-custom-days" class="nera-se__custom-label">
							<?php esc_html_e( 'Or enter a custom number of days (1–183):', 'nera-self-exclusion' ); ?>
						</label>
						<input
							type="number"
							id="pause-custom-days"
							name="pause_days_custom"
							class="nera-se__custom-input"
							min="1"
							max="183"
							placeholder="<?php esc_attr_e( 'e.g. 14', 'nera-self-exclusion' ); ?>"
						/>
					</div>
				</fieldset>

				<button type="submit" class="nera-se__submit nera-se__submit--pause">
					<span class="material-symbols-outlined" aria-hidden="true">bedtime</span>
					<?php esc_html_e( 'Pause my account', 'nera-self-exclusion' ); ?>
				</button>
			</form>
		</div><!-- /.nera-se__card--pause -->

		<?php /* -------- SUSPEND FORM -------- */ ?>
		<div class="nera-se__card nera-se__card--suspend">
			<div class="nera-se__card-header">
				<span class="material-symbols-outlined nera-se__card-icon" aria-hidden="true">pause_circle</span>
				<div>
					<h3 class="nera-se__card-title"><?php esc_html_e( 'Longer suspension', 'nera-self-exclusion' ); ?></h3>
					<p class="nera-se__card-subtitle"><?php esc_html_e( 'Suspend your account for 6 months to 5 years. It will reactivate automatically.', 'nera-self-exclusion' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( wc_get_account_endpoint_url( 'account-status' ) ); ?>" class="nera-se__form" id="nera-se-suspend-form">
				<input type="hidden" name="action" value="nera_account_suspend" />
				<?php wp_nonce_field( 'nera_account_suspend', 'nera_account_suspend_nonce' ); ?>
				<input type="hidden" name="nera_account_user_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" />

				<fieldset class="nera-se__fieldset">
					<legend class="nera-se__legend"><?php esc_html_e( 'How long would you like to suspend?', 'nera-self-exclusion' ); ?></legend>

					<div class="nera-se__radio-pills">
						<?php
						$suspend_presets = array(
							6  => __( '6 months', 'nera-self-exclusion' ),
							12 => __( '1 year', 'nera-self-exclusion' ),
							24 => __( '2 years', 'nera-self-exclusion' ),
							60 => __( '5 years', 'nera-self-exclusion' ),
						);
						foreach ( $suspend_presets as $months => $preset_label ) :
							?>
							<label class="nera-se__pill" for="suspend-preset-<?php echo esc_attr( $months ); ?>">
								<input
									type="radio"
									id="suspend-preset-<?php echo esc_attr( $months ); ?>"
									name="suspend_months"
									value="<?php echo esc_attr( $months ); ?>"
									class="nera-se__pill-radio"
									<?php checked( 6, $months ); ?>
								/>
								<span class="nera-se__pill-label"><?php echo esc_html( $preset_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<button type="submit" class="nera-se__submit nera-se__submit--suspend">
					<span class="material-symbols-outlined" aria-hidden="true">pause_circle</span>
					<?php esc_html_e( 'Suspend my account', 'nera-self-exclusion' ); ?>
				</button>
			</form>
		</div><!-- /.nera-se__card--suspend -->

		<?php /* -------- CLOSE FORM -------- */ ?>
		<div class="nera-se__card nera-se__card--danger">
			<div class="nera-se__card-header">
				<span class="material-symbols-outlined nera-se__card-icon" aria-hidden="true">lock</span>
				<div>
					<h3 class="nera-se__card-title"><?php esc_html_e( 'Permanently close account', 'nera-self-exclusion' ); ?></h3>
					<p class="nera-se__card-subtitle"><?php esc_html_e( 'This is irreversible. Once closed, your account cannot be reopened.', 'nera-self-exclusion' ); ?></p>
				</div>
			</div>

			<div class="nera-se__warning">
				<span class="material-symbols-outlined nera-se__warning-icon" aria-hidden="true">warning</span>
				<div>
					<strong><?php esc_html_e( 'Warning: this action is permanent and cannot be undone.', 'nera-self-exclusion' ); ?></strong>
					<p><?php esc_html_e( 'Closing your account will immediately log you out and permanently prevent you from logging back in. You will lose access to your competition history and any active entries. Please contact support before proceeding if you have any questions.', 'nera-self-exclusion' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( wc_get_account_endpoint_url( 'account-status' ) ); ?>" class="nera-se__form" id="nera-se-close-form">
				<input type="hidden" name="action" value="nera_account_close" />
				<?php wp_nonce_field( 'nera_account_close', 'nera_account_close_nonce' ); ?>
				<input type="hidden" name="nera_account_user_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" />

				<div class="nera-se__confirm-fields">
					<label class="nera-se__check-label">
						<input
							type="checkbox"
							name="confirm_check"
							value="1"
							id="nera-se-confirm-check"
							class="nera-se__confirm-checkbox"
						/>
						<span><?php esc_html_e( 'I understand this will permanently close my account and cannot be reversed.', 'nera-self-exclusion' ); ?></span>
					</label>

					<label for="nera-se-confirm-text" class="nera-se__confirm-text-label">
						<?php esc_html_e( 'Type CLOSE to confirm:', 'nera-self-exclusion' ); ?>
					</label>
					<input
						type="text"
						id="nera-se-confirm-text"
						name="confirm_text"
						class="nera-se__confirm-text-input"
						placeholder="<?php esc_attr_e( 'CLOSE', 'nera-self-exclusion' ); ?>"
						autocomplete="off"
					/>
				</div>

				<button type="submit" class="nera-se__submit nera-se__submit--close nera-se__close-submit" disabled aria-disabled="true">
					<span class="material-symbols-outlined" aria-hidden="true">lock</span>
					<?php esc_html_e( 'Permanently close my account', 'nera-self-exclusion' ); ?>
				</button>
			</form>
		</div><!-- /.nera-se__card--danger -->

	<?php endif; ?>

</section><!-- /.nera-se -->
