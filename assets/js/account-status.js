/**
 * Nera Self-Exclusion — account-status page script.
 *
 * No dependencies. Plain IIFE. Reads localised config from window.neraSelfExclusion
 * (populated by Nera_SE_Assets::enqueue via wp_localize_script).
 *
 * Responsibilities:
 *  1. Close form: enable the submit only when the checkbox is ticked AND the
 *     text field (trimmed, case-sensitive) equals "CLOSE". Show a final
 *     window.confirm() on submit; cancel aborts the POST.
 *  2. Pause form: sync the custom-days text input with the preset radio group —
 *     filling the custom input unchecks preset radios; selecting a preset clears
 *     the custom input. Ensure the active value is always written to the real
 *     `pause_days` hidden field (or to the radio that matches) before submit.
 */
( function () {
	'use strict';

	/* ----------------------------------------------------------------
	   Localised strings (with hard-coded fallbacks)
	   ---------------------------------------------------------------- */
	var l10n = window.neraSelfExclusion || {};
	var MSG_CONFIRM_CLOSE = l10n.confirmClose ||
		'This will permanently close your account and cannot be reversed. Continue?';

	/* ----------------------------------------------------------------
	   Helper: querySelector with null-safe return
	   ---------------------------------------------------------------- */
	function qs( selector, context ) {
		return ( context || document ).querySelector( selector );
	}
	function qsa( selector, context ) {
		return ( context || document ).querySelectorAll( selector );
	}

	/* ================================================================
	   1. CLOSE FORM — enable/disable + confirm
	   ================================================================ */
	var closeForm    = qs( '#nera-se-close-form' );
	var closeSubmit  = closeForm ? qs( '.nera-se__close-submit', closeForm ) : null;
	var confirmCheck = closeForm ? qs( '#nera-se-confirm-check', closeForm ) : null;
	var confirmText  = closeForm ? qs( '#nera-se-confirm-text', closeForm ) : null;

	function updateCloseSubmit() {
		if ( ! closeSubmit || ! confirmCheck || ! confirmText ) {
			return;
		}
		var ready = confirmCheck.checked &&
		            confirmText.value.trim() === 'CLOSE';

		closeSubmit.disabled     = ! ready;
		closeSubmit.setAttribute( 'aria-disabled', ready ? 'false' : 'true' );
	}

	if ( confirmCheck ) {
		confirmCheck.addEventListener( 'change', updateCloseSubmit );
	}
	if ( confirmText ) {
		confirmText.addEventListener( 'input', updateCloseSubmit );
	}

	// Final safety confirm on submit.
	if ( closeForm ) {
		closeForm.addEventListener( 'submit', function ( e ) {
			// Guard: button must be enabled (in case someone removes the attribute).
			if ( closeSubmit && closeSubmit.disabled ) {
				e.preventDefault();
				return;
			}
			if ( ! window.confirm( MSG_CONFIRM_CLOSE ) ) {
				e.preventDefault();
			}
		} );
	}

	/* ================================================================
	   2. PAUSE FORM — custom-days ↔ preset radio sync
	   ================================================================ */
	var pauseForm       = qs( '#nera-se-pause-form' );
	var pauseCustom     = pauseForm ? qs( '#pause-custom-days', pauseForm ) : null;
	var pausePresets    = pauseForm ? qsa( '.nera-se__pill-radio[name="pause_days"]', pauseForm ) : [];

	/**
	 * When the user types a value in the custom input, uncheck all preset radios
	 * so only the custom value will be submitted (the input shares the name
	 * "pause_days" when populated — handled by renaming on input below).
	 */
	if ( pauseCustom ) {
		pauseCustom.addEventListener( 'input', function () {
			var val = this.value.trim();
			if ( val !== '' ) {
				// Uncheck all presets — the custom input becomes the source of truth.
				for ( var i = 0; i < pausePresets.length; i++ ) {
					pausePresets[ i ].checked = false;
				}
				// Give the custom input the real field name so it is submitted.
				this.name = 'pause_days';
			} else {
				// Empty — revert to radio name so a preset can win again.
				this.name = 'pause_days_custom';
				// Re-check the default preset (7 days).
				for ( var j = 0; j < pausePresets.length; j++ ) {
					if ( pausePresets[ j ].value === '7' ) {
						pausePresets[ j ].checked = true;
						break;
					}
				}
			}
		} );
	}

	// When a preset radio is selected, clear the custom input and ensure it
	// is not submitted (revert its name).
	for ( var k = 0; k < pausePresets.length; k++ ) {
		pausePresets[ k ].addEventListener( 'change', function () {
			if ( pauseCustom ) {
				pauseCustom.value = '';
				pauseCustom.name  = 'pause_days_custom';
			}
		} );
	}

	// On form submit: validate custom days are within bounds if populated.
	if ( pauseForm ) {
		pauseForm.addEventListener( 'submit', function ( e ) {
			if ( ! pauseCustom || pauseCustom.value.trim() === '' ) {
				return; // Using a preset — server will validate.
			}
			var days = parseInt( pauseCustom.value.trim(), 10 );
			if ( isNaN( days ) || days < 1 || days > 183 ) {
				e.preventDefault();
				alert( 'Please enter a number of days between 1 and 183.' );
			}
		} );
	}

	// Initialise: ensure the close submit starts disabled.
	updateCloseSubmit();

} )();
