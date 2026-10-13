/**
 * Review screen: autosave of corrections and notes.
 *
 * Every row saves itself through the REST API (PATCH /relecture/v1/items/<id>) after a
 * pause in typing, when a field loses focus, on Ctrl/Cmd+S, or right away for selects and
 * checkboxes. Each request sends the version the row started from: if somebody else saved
 * in the meantime, the server answers 409 and nothing is overwritten.
 *
 * Plain DOM, no build step. Data from the server is only ever written with textContent
 * or value, never as HTML.
 *
 * @package Relecture
 */

/* global wp */
( function () {
	'use strict';

	const config = window.relectureReview || {};
	const table = document.querySelector( '.relecture-table' );
	if ( ! table || ! config.reviewId || ! window.wp || ! wp.apiFetch ) {
		return;
	}

	const { __ } = wp.i18n;
	const DELAY = 1200;
	const PREFILL_KEY = 'relecture.prefill';
	const rows = new Map();
	const stateBox = document.querySelector( '.relecture-save-state' );
	const prefill = document.getElementById( 'relecture-prefill' );
	let globalState = '';

	/* ------------------------------------------------------------ Helpers */

	function rowState( tr ) {
		const id = tr.dataset.id;
		if ( ! rows.has( id ) ) {
			rows.set( id, {
				id,
				el: tr,
				version: parseInt( tr.dataset.version, 10 ) || 0,
				dirty: new Set(),
				timer: 0,
				saving: null,
				again: false,
				failed: false,
				conflict: false,
			} );
		}
		return rows.get( id );
	}

	function field( tr, name ) {
		return tr.querySelector( '[data-field="' + name + '"]' );
	}

	function originalText( tr ) {
		const box = tr.querySelector( '.relecture-original-text' );
		return box ? box.textContent : '';
	}

	function normalize( text ) {
		return String( text ).replace( /\r\n?/g, '\n' ).replace( /[ \t]+\n/g, '\n' ).trim();
	}

	function sameAsOriginal( tr, value ) {
		return normalize( value ) === normalize( originalText( tr ) );
	}

	function supportsFieldSizing() {
		return !! ( window.CSS && CSS.supports && CSS.supports( 'field-sizing', 'content' ) );
	}

	function autosize( textarea ) {
		if ( supportsFieldSizing() ) {
			return;
		}
		textarea.style.height = 'auto';
		textarea.style.height = textarea.scrollHeight + 2 + 'px';
	}

	function message( tr, text ) {
		const box = tr.querySelector( '.relecture-row-message' );
		if ( ! box ) {
			return;
		}
		box.textContent = text || '';
		box.hidden = ! text;
	}

	/* ---------------------------------------------------------- Row state */

	function refreshRow( tr ) {
		const correction = field( tr, 'correction' );
		const note = field( tr, 'note' );
		const guideline = field( tr, 'note_type' );
		const hasCorrection = !! correction && '' !== correction.value.trim() && ! sameAsOriginal( tr, correction.value );
		const hasNote = !! note && '' !== note.value.trim();
		tr.classList.toggle( 'has-correction', hasCorrection );
		tr.classList.toggle( 'has-note', hasNote );
		tr.classList.toggle( 'is-guideline', hasNote && !! guideline && guideline.checked );
		tr.dataset.feedback = hasCorrection || hasNote ? '1' : '0';
		refreshCounters();
	}

	function refreshCounters() {
		const counts = {
			corrections: table.querySelectorAll( 'tr.has-correction' ).length,
			notes: table.querySelectorAll( 'tr.has-note' ).length,
			guidelines: table.querySelectorAll( 'tr.is-guideline' ).length,
		};
		Object.keys( counts ).forEach( ( key ) => {
			const box = document.querySelector( '[data-count="' + key + '"]' );
			if ( box ) {
				box.textContent = String( counts[ key ] );
			}
		} );
	}

	/* ----------------------------------------------------- Global state */

	function setGlobal( state ) {
		if ( ! stateBox || state === globalState ) {
			return;
		}
		globalState = state;
		stateBox.className = 'relecture-save-state is-' + state;
		stateBox.textContent = '';

		const labels = {
			dirty: __( 'Unsaved changes', 'relecture' ),
			saving: __( 'Saving…', 'relecture' ),
			saved: __( 'All changes saved', 'relecture' ),
			error: __( 'Some changes could not be saved.', 'relecture' ),
		};
		stateBox.appendChild( document.createTextNode( labels[ state ] || '' ) );
		if ( 'error' === state ) {
			const retry = document.createElement( 'button' );
			retry.type = 'button';
			retry.className = 'button-link';
			retry.textContent = __( 'Retry', 'relecture' );
			retry.addEventListener( 'click', saveAll );
			stateBox.appendChild( document.createTextNode( ' ' ) );
			stateBox.appendChild( retry );
		}
	}

	function refreshGlobal() {
		let saving = false;
		let dirty = false;
		let error = false;
		rows.forEach( ( state ) => {
			saving = saving || !! state.saving;
			dirty = dirty || state.dirty.size > 0;
			error = error || ( state.failed && state.dirty.size > 0 );
		} );
		if ( saving ) {
			setGlobal( 'saving' );
		} else if ( error ) {
			setGlobal( 'error' );
		} else if ( dirty ) {
			setGlobal( 'dirty' );
		} else if ( globalState ) {
			setGlobal( 'saved' );
		}
	}

	function hasPending() {
		let pending = false;
		rows.forEach( ( state ) => {
			pending = pending || !! state.saving || state.dirty.size > 0;
		} );
		return pending;
	}

	/* -------------------------------------------------------------- Save */

	function payload( tr, names ) {
		const data = {};
		names.forEach( ( name ) => {
			const input = field( tr, name );
			if ( ! input ) {
				return;
			}
			if ( 'note_type' === name ) {
				data.note_type = input.checked ? 'guideline' : 'post';
			} else {
				data[ name ] = input.value;
			}
		} );
		return data;
	}

	function schedule( tr, name, immediate ) {
		const state = rowState( tr );
		state.dirty.add( name );
		tr.classList.add( 'is-dirty' );
		clearTimeout( state.timer );
		refreshGlobal();
		if ( immediate ) {
			save( tr );
		} else {
			state.timer = setTimeout( () => save( tr ), DELAY );
		}
	}

	function save( tr ) {
		const state = rowState( tr );
		clearTimeout( state.timer );
		if ( state.saving ) {
			state.again = true;
			return state.saving;
		}
		if ( ! state.dirty.size || state.conflict ) {
			return Promise.resolve();
		}

		const names = Array.from( state.dirty );
		state.dirty.clear();
		const data = payload( tr, names );
		data.expected_version = state.version;

		tr.classList.add( 'is-saving' );
		state.saving = wp
			.apiFetch( {
				path: config.itemPath + state.id,
				method: 'PATCH',
				data,
			} )
			.then( ( item ) => {
				state.version = item.version;
				tr.dataset.version = String( item.version );
				state.failed = false;
				tr.classList.remove( 'is-error' );
				message( tr, '' );
				// The server may have normalized the row (a correction identical to the
				// original is no correction, a new correction is “to validate”).
				const status = field( tr, 'correction_status' );
				if ( status && ! state.dirty.has( 'correction_status' ) ) {
					status.value = item.correction_status || 'pending';
				}
			} )
			.catch( ( error ) => {
				names.forEach( ( name ) => state.dirty.add( name ) );
				state.failed = true;
				if ( error && 'relecture_conflict' === error.code ) {
					state.conflict = true;
					tr.classList.add( 'is-conflict' );
				} else {
					tr.classList.add( 'is-error' );
				}
				message( tr, error && error.message ? error.message : __( 'Not saved.', 'relecture' ) );
			} )
			.finally( () => {
				tr.classList.remove( 'is-saving' );
				state.saving = null;
				if ( ! state.dirty.size ) {
					tr.classList.remove( 'is-dirty' );
				}
				if ( state.again && ! state.failed ) {
					state.again = false;
					save( tr );
				}
				state.again = false;
				refreshGlobal();
			} );
		refreshGlobal();
		return state.saving;
	}

	function saveAll() {
		const pending = [];
		rows.forEach( ( state ) => {
			state.failed = false;
			if ( state.dirty.size ) {
				pending.push( save( state.el ) );
			}
		} );
		return Promise.all( pending );
	}

	/* ----------------------------------------------------- Interactions */

	table.querySelectorAll( 'textarea.relecture-field' ).forEach( autosize );

	if ( prefill ) {
		try {
			if ( '0' === window.localStorage.getItem( PREFILL_KEY ) ) {
				prefill.checked = false;
			}
		} catch ( e ) {
			// Storage unavailable (private window…): keep the default.
		}
		prefill.addEventListener( 'change', () => {
			try {
				window.localStorage.setItem( PREFILL_KEY, prefill.checked ? '1' : '0' );
			} catch ( e ) {
				// Not remembered, no harm.
			}
		} );
	}

	document.querySelectorAll( '[data-filter]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const mode = button.dataset.filter;
			document.querySelectorAll( '[data-filter]' ).forEach( ( other ) => {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );
			table.querySelectorAll( 'tr.relecture-row' ).forEach( ( tr ) => {
				const withFeedback = '1' === tr.dataset.feedback;
				tr.hidden = ( 'feedback' === mode && ! withFeedback ) || ( 'empty' === mode && withFeedback );
			} );
		} );
	} );

	// The column headers stick right under the toolbar, whose height depends on wrapping.
	const toolbar = document.querySelector( '.relecture-toolbar' );
	const wrap = document.querySelector( '.relecture-wrap' );
	function syncToolbarHeight() {
		if ( toolbar && wrap ) {
			wrap.style.setProperty( '--relecture-toolbar-height', toolbar.offsetHeight + 'px' );
		}
	}
	window.addEventListener( 'resize', syncToolbarHeight );
	syncToolbarHeight();

	if ( config.readOnly ) {
		return;
	}

	table.addEventListener( 'focusin', ( event ) => {
		const input = event.target;
		if ( ! input.matches( 'textarea[data-field="correction"]' ) || input.readOnly ) {
			return;
		}
		if ( prefill && prefill.checked && '' === input.value ) {
			input.value = originalText( input.closest( 'tr' ) );
			input.dataset.prefilled = '1';
			autosize( input );
		}
	} );

	table.addEventListener( 'focusout', ( event ) => {
		const input = event.target;
		if ( ! input.matches( 'textarea.relecture-field' ) ) {
			return;
		}
		const tr = input.closest( 'tr' );
		// Untouched prefill (or edits undone): the field goes back to empty.
		if ( 'correction' === input.dataset.field && sameAsOriginal( tr, input.value ) ) {
			input.value = '';
			delete input.dataset.prefilled;
			autosize( input );
			refreshRow( tr );
		}
		if ( rowState( tr ).dirty.size ) {
			save( tr );
		}
	} );

	table.addEventListener( 'input', ( event ) => {
		const input = event.target;
		if ( ! input.matches( 'textarea.relecture-field' ) ) {
			return;
		}
		const tr = input.closest( 'tr' );
		autosize( input );
		refreshRow( tr );
		schedule( tr, input.dataset.field, false );
	} );

	table.addEventListener( 'change', ( event ) => {
		const input = event.target;
		if ( ! input.matches( 'select[data-field], input[type="checkbox"][data-field]' ) ) {
			return;
		}
		const tr = input.closest( 'tr' );
		refreshRow( tr );
		schedule( tr, input.dataset.field, true );
	} );

	table.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-action="copy-original"]' );
		if ( ! button ) {
			return;
		}
		const tr = button.closest( 'tr' );
		const correction = field( tr, 'correction' );
		correction.value = originalText( tr );
		correction.dataset.prefilled = '1';
		autosize( correction );
		correction.focus();
		refreshRow( tr );
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( ( event.ctrlKey || event.metaKey ) && 's' === String( event.key ).toLowerCase() ) {
			event.preventDefault();
			saveAll();
		}
	} );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( hasPending() ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
}() );
