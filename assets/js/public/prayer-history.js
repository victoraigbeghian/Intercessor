/* global intercessorHistory */
( function () {
	'use strict';

	const config = window.intercessorHistory || {};
	const i18n   = config.i18n || {};

	// ── Inline edit toggle ────────────────────────────────────────────────
	document.addEventListener( 'click', function ( e ) {
		const editBtn = e.target.closest( '[data-ipr-action="edit"]' );
		if ( ! editBtn ) return;

		e.preventDefault();
		const row  = editBtn.closest( '.ipr-user-row' );
		const form = row ? row.querySelector( '.ipr-edit-form' ) : null;
		if ( ! form ) return;

		const open = form.hidden;
		form.hidden = ! open;
		editBtn.textContent = open
			? ( i18n.cancelEdit || 'Cancel' )
			: ( i18n.edit       || 'Edit' );
	} );

	// ── Update form submission ────────────────────────────────────────────
	document.addEventListener( 'submit', async function ( e ) {
		const form = e.target.closest( '.ipr-update-form' );
		if ( ! form ) return;
		e.preventDefault();

		const submitBtn = form.querySelector( '[type="submit"]' );
		const msgEl     = form.querySelector( '.ipr-form-msg' );
		if ( submitBtn ) submitBtn.disabled = true;
		if ( msgEl )     { msgEl.textContent = ''; msgEl.className = 'ipr-form-msg'; }

		const body = new FormData( form );
		body.append( 'action', config.updateAction || 'intercessor_update_own_request' );
		body.append( 'nonce',  config.nonce        || '' );

		try {
			const res  = await fetch( config.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body } );
			const json = await res.json();

			if ( json.success ) {
				const row   = form.closest( '.ipr-user-row' );
				const badge = row ? row.querySelector( '.ipr-status-badge' ) : null;
				// A private request keeps its status when edited; the server
				// reports whether the request went back to pending.
				const nowPending = ! json.data || json.data.status !== 'private';
				if ( badge && nowPending ) {
					badge.className   = 'ipr-status-badge intercessor-status-pending';
					badge.textContent = i18n.pendingLabel || 'Pending';
				}
				// Update the visible subject/content in the row summary.
				const subjectEl = row ? row.querySelector( '.ipr-row-subject' ) : null;
				const contentEl = row ? row.querySelector( '.ipr-row-content' ) : null;
				if ( subjectEl ) subjectEl.textContent = form.querySelector( '[name="subject"]' ).value;
				if ( contentEl ) contentEl.textContent = form.querySelector( '[name="content"]' ).value;

				// Collapse the edit form and restore the button label.
				const editForm = form.closest( '.ipr-edit-form' );
				if ( editForm ) editForm.hidden = true;
				const editBtn = row ? row.querySelector( '[data-ipr-action="edit"]' ) : null;
				if ( editBtn ) editBtn.textContent = i18n.edit || 'Edit';

				if ( msgEl ) {
					msgEl.className   = 'ipr-form-msg ipr-form-msg--success';
					msgEl.textContent = ( json.data && json.data.message ) || i18n.updateSuccess || 'Updated — awaiting review.';
				}
			} else {
				if ( msgEl ) {
					msgEl.className   = 'ipr-form-msg ipr-form-msg--error';
					msgEl.textContent = ( json.data && json.data.message ) || i18n.error || 'An error occurred.';
				}
			}
		} catch ( err ) {
			if ( msgEl ) {
				msgEl.className   = 'ipr-form-msg ipr-form-msg--error';
				msgEl.textContent = i18n.networkError || 'Network error. Please try again.';
			}
		} finally {
			if ( submitBtn ) submitBtn.disabled = false;
		}
	} );

	// ── Delete ───────────────────────────────────────────────────────────
	document.addEventListener( 'click', async function ( e ) {
		const deleteBtn = e.target.closest( '[data-ipr-action="delete"]' );
		if ( ! deleteBtn ) return;

		e.preventDefault();

		const msg = i18n.confirmDelete
			|| 'Are you sure you want to delete this prayer request? This cannot be undone.';

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( msg ) ) return;

		const requestId = deleteBtn.dataset.requestId || '';
		if ( ! requestId ) return;

		deleteBtn.disabled = true;

		const body = new FormData();
		body.append( 'action',     config.deleteAction || 'intercessor_delete_own_request' );
		body.append( 'nonce',      config.nonce        || '' );
		body.append( 'request_id', requestId );

		try {
			const res  = await fetch( config.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body } );
			const json = await res.json();

			if ( json.success ) {
				const row = deleteBtn.closest( '.ipr-user-row' );
				if ( row ) {
					row.style.transition = 'opacity 0.3s ease';
					row.style.opacity    = '0';
					setTimeout( () => row.remove(), 320 );
				}
			} else {
				// eslint-disable-next-line no-alert
				window.alert(
					( json.data && json.data.message )
					|| i18n.deleteError
					|| 'Could not delete the prayer request.'
				);
				deleteBtn.disabled = false;
			}
		} catch ( err ) {
			// eslint-disable-next-line no-alert
			window.alert( i18n.networkError || 'Network error. Please try again.' );
			deleteBtn.disabled = false;
		}
	} );
}() );
