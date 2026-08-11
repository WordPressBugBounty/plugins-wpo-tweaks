/**
 * DietPress admin scripts.
 *
 * Handles client-side tab navigation, conditional field visibility,
 * and Tools tab functionality (import/export, profiles, recommendations).
 */
( function() {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function() {
		initTabs();
		initConditionalFields();
		initToolsExport();
		initToolsImport();
		initToolsProfiles();
		initToolsAnalyze();
		initRestoreDefaults();
		initSectionToggles();
		initOptionLocks();
		initCacheActions();
	} );

	/* ============================
	 * Option locks
	 * ============================ */

	/**
	 * Mirror, without reloading, the locks PHP applies to option cards.
	 *
	 * A card that depends on another option carries the option it depends on,
	 * the state that locks it, whether the lock is hard (the toggle cannot be
	 * used) or soft (it only says the option does nothing right now), and the
	 * reason to show. PHP enforces the same rules on save and on render, so a
	 * browser with no JavaScript loses the live update, never the rule.
	 */
	function initOptionLocks() {
		var cards = document.querySelectorAll( '.core-diet-option-card[data-lock-on]' );

		if ( ! cards.length ) {
			return;
		}

		var byController = {};

		Array.prototype.forEach.call( cards, function( card ) {
			var id = card.getAttribute( 'data-lock-on' );

			if ( ! byController[ id ] ) {
				byController[ id ] = [];
			}

			byController[ id ].push( card );
		} );

		Object.keys( byController ).forEach( function( id ) {
			var controller = document.getElementById( id );

			if ( ! controller ) {
				return;
			}

			controller.addEventListener( 'change', function() {
				byController[ id ].forEach( function( card ) {
					applyOptionLock( card, controller.checked );
				} );
			} );
		} );
	}

	/**
	 * Lock or unlock a single option card.
	 */
	function applyOptionLock( card, controllerChecked ) {
		var lockWhen = '1' === card.getAttribute( 'data-lock-when' );
		var hard     = 'hard' === card.getAttribute( 'data-lock-mode' );
		var locked   = controllerChecked === lockWhen;
		var checkbox = card.querySelector( 'input[type="checkbox"]' );
		var notice   = card.querySelector( '.core-diet-option-notice' );
		var text     = card.querySelector( '.core-diet-option-notice-text' );

		card.classList.toggle( 'core-diet-option-locked', locked && hard );
		card.classList.toggle( 'core-diet-option-inactive', locked && ! hard );

		if ( notice ) {
			if ( locked ) {
				if ( text ) {
					text.textContent = card.getAttribute( 'data-lock-text' ) || '';
				}
				notice.className = 'core-diet-option-notice core-diet-option-notice-' + ( hard ? 'locked' : 'inactive' );
				notice.hidden    = false;
			} else {
				notice.hidden = true;
			}
		}

		if ( ! hard || ! checkbox ) {
			return;
		}

		// A disabled checkbox is not submitted, so its value travels in a
		// hidden field while the lock lasts and the preference is not lost.
		var hidden = card.querySelector( '.core-diet-locked-value' );

		if ( locked ) {
			checkbox.disabled = true;

			if ( ! hidden ) {
				hidden = document.createElement( 'input' );
				hidden.type      = 'hidden';
				hidden.className = 'core-diet-locked-value';
				hidden.name      = checkbox.name;
				card.appendChild( hidden );
			}

			hidden.value = checkbox.checked ? '1' : '';
		} else {
			checkbox.disabled = false;

			if ( hidden ) {
				hidden.parentNode.removeChild( hidden );
			}
		}
	}

	/* ============================
	 * Tab navigation
	 * ============================ */

	/**
	 * Tab navigation.
	 *
	 * Settings tabs live inside a form, the Tools tab lives outside.
	 * Both use the same CSS class for panel switching.
	 * The submit button is hidden when the Tools tab is active.
	 */
	function initTabs() {
		var tabLinks   = document.querySelectorAll( '.core-diet-tabs .core-diet-nav-tab' );
		var tabPanels  = document.querySelectorAll( '.core-diet-tab-panel' );
		var submitWrap = document.querySelector( '.core-diet-submit-wrap' );

		if ( ! tabLinks.length || ! tabPanels.length ) {
			return;
		}

		function activateTab( tabId ) {
			tabLinks.forEach( function( link ) {
				if ( link.getAttribute( 'data-tab' ) === tabId ) {
					link.classList.add( 'nav-tab-active' );
				} else {
					link.classList.remove( 'nav-tab-active' );
				}
			} );

			tabPanels.forEach( function( panel ) {
				if ( panel.id === 'core-diet-tab-' + tabId ) {
					panel.classList.add( 'core-diet-tab-active' );
				} else {
					panel.classList.remove( 'core-diet-tab-active' );
				}
			} );

			// Scale and Tools are rendered outside the settings form and work
			// over AJAX, so the form's save button does not apply to them. The
			// cache tab IS inside the form and keeps its button.
			if ( submitWrap ) {
				var ownFormTabs = [ 'scale', 'tools' ];
				submitWrap.classList.toggle( 'core-diet-hidden', ownFormTabs.indexOf( tabId ) !== -1 );
			}
		}

		tabLinks.forEach( function( link ) {
			link.addEventListener( 'click', function( e ) {
				e.preventDefault();
				var tabId = this.getAttribute( 'data-tab' );
				activateTab( tabId );

				if ( history.replaceState ) {
					var url = new URL( window.location );
					url.searchParams.set( 'tab', tabId );
					url.hash = '';
					history.replaceState( null, '', url.toString() );
				}

				// Every form on the page, not just the first one: the page cache
				// tab has a form of its own and would otherwise send the visitor
				// back to whichever tab was open when the page loaded.
				document.querySelectorAll( 'input[name="_wp_http_referer"]' ).forEach( function( referer ) {
					var refUrl = new URL( referer.value, window.location.origin );
					refUrl.searchParams.set( 'tab', tabId );
					referer.value = refUrl.pathname + refUrl.search;
				} );
			} );
		} );

		// On load: activate tab from URL.
		var urlParams = new URLSearchParams( window.location.search );
		var tabParam  = urlParams.get( 'tab' );
		if ( tabParam && document.getElementById( 'core-diet-tab-' + tabParam ) ) {
			activateTab( tabParam );
		}
	}

	/* ============================
	 * Conditional fields
	 * ============================ */

	function initConditionalFields() {
		var revisionsSelect    = document.getElementById( 'core_diet_revisions_mode' );
		var revisionsLimitWrap = document.getElementById( 'core_diet_revisions_limit_wrap' );

		if ( ! revisionsSelect || ! revisionsLimitWrap ) {
			return;
		}

		function toggleRevisionsLimit() {
			if ( revisionsSelect.value === 'limit' ) {
				revisionsLimitWrap.classList.remove( 'core-diet-hidden' );
			} else {
				revisionsLimitWrap.classList.add( 'core-diet-hidden' );
			}
		}

		toggleRevisionsLimit();
		revisionsSelect.addEventListener( 'change', toggleRevisionsLimit );
	}

	/* ============================
	 * Export
	 * ============================ */

	function initToolsExport() {
		var btn = document.getElementById( 'core-diet-export-btn' );
		if ( ! btn || typeof coreDietAdmin === 'undefined' ) {
			return;
		}

		btn.addEventListener( 'click', function() {
			btn.disabled = true;

			var formData = new FormData();
			formData.append( 'action', 'core_diet_export' );
			formData.append( 'security', coreDietAdmin.nonce );

			fetch( coreDietAdmin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( result ) {
				if ( result.success ) {
					var json = JSON.stringify( result.data, null, 2 );
					var blob = new Blob( [ json ], { type: 'application/json' } );
					var url  = URL.createObjectURL( blob );
					var a    = document.createElement( 'a' );
					a.href     = url;
					a.download = 'core-diet-settings-' + new Date().toISOString().slice( 0, 10 ) + '.json';
					document.body.appendChild( a );
					a.click();
					document.body.removeChild( a );
					URL.revokeObjectURL( url );
				} else {
					alert( result.data || coreDietAdmin.strings.error );
				}
			} )
			.catch( function() { alert( coreDietAdmin.strings.error ); } )
			.finally( function() { btn.disabled = false; } );
		} );
	}

	/* ============================
	 * Import
	 * ============================ */

	function initToolsImport() {
		var fileInput = document.getElementById( 'core-diet-import-file' );
		var status    = document.getElementById( 'core-diet-import-status' );

		if ( ! fileInput || typeof coreDietAdmin === 'undefined' ) {
			return;
		}

		fileInput.addEventListener( 'change', function() {
			var file = fileInput.files[0];
			if ( ! file ) {
				return;
			}

			if ( ! file.name.endsWith( '.json' ) ) {
				status.textContent = coreDietAdmin.strings.error;
				status.className   = 'core-diet-import-status core-diet-status-error';
				return;
			}

			if ( ! confirm( coreDietAdmin.strings.confirmImport ) ) {
				fileInput.value = '';
				return;
			}

			status.textContent = '';
			status.className   = 'core-diet-import-status';

			var formData = new FormData();
			formData.append( 'action', 'core_diet_import' );
			formData.append( 'security', coreDietAdmin.nonce );
			formData.append( 'file', file );

			fetch( coreDietAdmin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( result ) {
				if ( result.success ) {
					status.textContent = coreDietAdmin.strings.importSuccess;
					status.className   = 'core-diet-import-status core-diet-status-success';
					setTimeout( function() { window.location.reload(); }, 1000 );
				} else {
					status.textContent = result.data || coreDietAdmin.strings.error;
					status.className   = 'core-diet-import-status core-diet-status-error';
				}
			} )
			.catch( function() {
				status.textContent = coreDietAdmin.strings.error;
				status.className   = 'core-diet-import-status core-diet-status-error';
			} )
			.finally( function() { fileInput.value = ''; } );
		} );
	}

	/* ============================
	 * Profiles
	 * ============================ */

	function initToolsProfiles() {
		var buttons = document.querySelectorAll( '.core-diet-apply-profile' );
		if ( ! buttons.length || typeof coreDietAdmin === 'undefined' ) {
			return;
		}

		buttons.forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var profileId   = btn.getAttribute( 'data-profile' );
				var profileName = btn.getAttribute( 'data-name' );

				if ( ! confirm( coreDietAdmin.strings.confirmProfile.replace( '%s', profileName ) ) ) {
					return;
				}

				btn.disabled    = true;
				btn.textContent = '...';

				var formData = new FormData();
				formData.append( 'action', 'core_diet_apply_profile' );
				formData.append( 'security', coreDietAdmin.nonce );
				formData.append( 'profile', profileId );

				fetch( coreDietAdmin.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( result ) {
					if ( result.success ) {
						var url = new URL( window.location.href );
						var tab = url.searchParams.get( 'tab' ) || 'scale';
						url.searchParams.set( 'tab', tab );
						url.searchParams.delete( 'settings-updated' );
						window.location.href = url.toString();
					} else {
						alert( result.data || coreDietAdmin.strings.error );
						btn.disabled = false;
					}
				} )
				.catch( function() {
					alert( coreDietAdmin.strings.error );
					btn.disabled = false;
				} );
			} );
		} );
	}

	/* ============================
	 * Recommendations / Analyze
	 * ============================ */

	function initToolsAnalyze() {
		var btn        = document.getElementById( 'core-diet-analyze-btn' );
		var resultsDiv = document.getElementById( 'core-diet-recommendations-results' );

		if ( ! btn || ! resultsDiv || typeof coreDietAdmin === 'undefined' ) {
			return;
		}

		btn.addEventListener( 'click', function() {
			btn.disabled = true;
			btn.textContent = coreDietAdmin.strings.analyzing;
			resultsDiv.classList.add( 'core-diet-hidden' );
			resultsDiv.innerHTML = '';

			var formData = new FormData();
			formData.append( 'action', 'core_diet_analyze' );
			formData.append( 'security', coreDietAdmin.nonce );

			fetch( coreDietAdmin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( result ) {
				if ( result.success ) {
					renderRecommendations( result.data.recommendations, resultsDiv, result.data.estimates || {} );
				} else {
					resultsDiv.innerHTML = '<p class="core-diet-notice-error">' +
						escHtml( result.data || coreDietAdmin.strings.error ) + '</p>';
				}
				resultsDiv.classList.remove( 'core-diet-hidden' );
			} )
			.catch( function() {
				resultsDiv.innerHTML = '<p class="core-diet-notice-error">' +
					escHtml( coreDietAdmin.strings.error ) + '</p>';
				resultsDiv.classList.remove( 'core-diet-hidden' );
			} )
			.finally( function() {
				btn.disabled    = false;
				btn.textContent = '';
				var icon = document.createElement( 'span' );
				icon.className = 'dashicons dashicons-search core-diet-btn-icon';
				btn.appendChild( icon );
				btn.appendChild( document.createTextNode( ' ' + coreDietAdmin.strings.analyzeBtn ) );
			} );
		} );
	}

	/**
	 * Render recommendation cards grouped by tab, with filters and save button.
	 */
	function renderRecommendations( recommendations, container, estimates ) {
		if ( ! recommendations || ! recommendations.length ) {
			container.innerHTML = '<div class="core-diet-no-recommendations">' +
				'<span class="dashicons dashicons-yes-alt"></span>' +
				'<p>' + escHtml( coreDietAdmin.strings.noRecommendations ) + '</p></div>';
			return;
		}

		var s = coreDietAdmin.strings;
		var riskLabels = { safe: s.riskSafe, recommended: s.riskRecommended, moderate: s.riskModerate };
		var tabLabels  = { light: s.tabLight, moderate: s.tabModerate, strict: s.tabStrict, cache: s.tabCache };

		// Separate boolean (toggleable) from select (info-only) recommendations.
		var toggleable = [];
		var infoOnly   = [];

		recommendations.forEach( function( rec ) {
			if ( rec.value ) {
				infoOnly.push( rec );
			} else {
				toggleable.push( rec );
			}
		} );

		// Group toggleable by tab.
		var groups = {};
		toggleable.forEach( function( rec ) {
			if ( ! groups[ rec.tab ] ) {
				groups[ rec.tab ] = [];
			}
			groups[ rec.tab ].push( rec );
		} );

		var html = '';

		// Quick-enable buttons.
		html += '<div class="core-diet-rec-filters">';
		html += '<span class="core-diet-rec-filters-label">' + escHtml( s.quickSelect ) + '</span>';
		html += '<button type="button" class="button button-small core-diet-rec-filter" data-filter="all">' + escHtml( s.filterAll ) + '</button>';
		html += '<button type="button" class="button button-small core-diet-rec-filter" data-filter="safe">' + escHtml( s.filterSafe ) + '</button>';
		html += '<button type="button" class="button button-small core-diet-rec-filter" data-filter="safe-recommended">' + escHtml( s.filterSafeRec ) + '</button>';
		html += '<span class="core-diet-rec-counter" id="core-diet-rec-counter"></span>';
		html += '</div>';

		// Toggleable cards grouped by tab.
		// Same order as the tabs themselves. A tab missing from this list would
		// silently drop its recommendations instead of rendering them.
		var tabOrder = [ 'light', 'moderate', 'strict', 'cache' ];
		tabOrder.forEach( function( tab ) {
			if ( ! groups[ tab ] || ! groups[ tab ].length ) {
				return;
			}
			html += '<h3 class="core-diet-rec-group-title">' + escHtml( tabLabels[ tab ] || tab ) + '</h3>';
			html += '<div class="core-diet-cards-grid">';
			groups[ tab ].forEach( function( rec ) {
				html += buildRecCard( rec, riskLabels );
			} );
			html += '</div>';
		} );

		// Info-only cards (selects like heartbeat, revisions).
		if ( infoOnly.length ) {
			html += '<div class="core-diet-rec-info-cards">';
			infoOnly.forEach( function( rec ) {
				var riskLabel = riskLabels[ rec.risk ] || rec.risk;
				html += '<div class="core-diet-rec-info-card">';
				html += '<span class="core-diet-risk-badge core-diet-risk-' + escAttr( rec.risk ) + '">' + escHtml( riskLabel ) + '</span> ';
				html += '<strong>' + escHtml( rec.label ) + '</strong> &mdash; ';
				html += escHtml( rec.reason ) + ' ';
				html += '<em>' + escHtml( s.recTip ) + '</em>';
				html += '</div>';
			} );
			html += '</div>';
		}

		// Save button.
		html += '<div class="core-diet-rec-save-wrap">';
		html += '<button type="button" class="button button-primary" id="core-diet-save-recs">' + escHtml( s.saveRecs ) + '</button>';
		html += '</div>';

		container.innerHTML = html;

		// Store estimates for counter calculations.
		container._dpEstimates = estimates || {};

		// Init counter.
		updateRecCounter( container );

		// Quick-enable button handlers: check/uncheck toggles by risk level.
		container.querySelectorAll( '.core-diet-rec-filter' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var filter = btn.getAttribute( 'data-filter' );

				container.querySelectorAll( '.core-diet-option-card[data-risk]' ).forEach( function( card ) {
					var risk = card.getAttribute( 'data-risk' );
					var cb   = card.querySelector( '.core-diet-rec-toggle' );
					if ( ! cb ) {
						return;
					}
					var shouldEnable = ( filter === 'all' ) ||
						( filter === 'safe' && risk === 'safe' ) ||
						( filter === 'safe-recommended' && ( risk === 'safe' || risk === 'recommended' ) );
					cb.checked = shouldEnable;
				} );

				updateRecCounter( container );
			} );
		} );

		// Toggle change → update counter.
		container.querySelectorAll( '.core-diet-rec-toggle' ).forEach( function( cb ) {
			cb.addEventListener( 'change', function() {
				updateRecCounter( container );
			} );
		} );

		// Save button handler.
		var saveBtn = document.getElementById( 'core-diet-save-recs' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function() {
				saveBtn.disabled    = true;
				saveBtn.textContent = s.saving;

				var changes = {};
				container.querySelectorAll( '.core-diet-rec-toggle' ).forEach( function( cb ) {
					changes[ cb.getAttribute( 'data-key' ) ] = cb.checked ? true : false;
				} );

				var formData = new FormData();
				formData.append( 'action', 'core_diet_save_recommendations' );
				formData.append( 'security', coreDietAdmin.nonce );
				formData.append( 'settings', JSON.stringify( changes ) );

				fetch( coreDietAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( result ) {
					if ( result.success ) {
						saveBtn.textContent = s.savedRecs;
						setTimeout( function() {
							var url = new URL( window.location.href );
							url.searchParams.set( 'tab', url.searchParams.get( 'tab' ) || 'scale' );
							url.searchParams.delete( 'settings-updated' );
							window.location.href = url.toString();
						}, 800 );
					} else {
						/* eslint-disable no-alert */
						alert( result.data || s.error );
						saveBtn.disabled    = false;
						saveBtn.textContent = s.saveRecs;
					}
				} )
				.catch( function() {
					alert( s.error );
					saveBtn.disabled    = false;
					saveBtn.textContent = s.saveRecs;
				} );
			} );
		}
	}

	/**
	 * Build a single recommendation card HTML with toggle.
	 */
	function buildRecCard( rec, riskLabels ) {
		var riskLabel = riskLabels[ rec.risk ] || rec.risk;
		var fieldId   = 'core_diet_rec_' + rec.key;
		var html = '';

		html += '<div class="core-diet-option-card" data-risk="' + escAttr( rec.risk ) + '">';
		html += '<div class="core-diet-option-header">';
		html += '<div>';
		html += '<label class="core-diet-option-label" for="' + escAttr( fieldId ) + '">' + escHtml( rec.label ) + '</label>';
		html += '<span class="core-diet-risk-badge core-diet-risk-' + escAttr( rec.risk ) + '">' + escHtml( riskLabel ) + '</span>';
		html += '</div>';
		html += '<label class="core-diet-toggle">';
		html += '<input type="checkbox" id="' + escAttr( fieldId ) + '" class="core-diet-rec-toggle" data-key="' + escAttr( rec.key ) + '">';
		html += '<span class="core-diet-toggle-slider"></span>';
		html += '</label>';
		html += '</div>';
		html += '<p class="core-diet-option-desc">' + escHtml( rec.reason ) + '</p>';

		if ( rec.notice ) {
			html += '<p class="core-diet-option-notice core-diet-option-notice-warning">';
			html += '<span class="dashicons dashicons-warning" aria-hidden="true"></span>';
			html += '<span>' + escHtml( rec.notice ) + '</span>';
			html += '</p>';
		}

		html += '</div>';

		return html;
	}

	/**
	 * Update the enabled/total counter for recommendations.
	 */
	function updateRecCounter( container ) {
		var counter = document.getElementById( 'core-diet-rec-counter' );
		if ( ! counter ) {
			return;
		}
		var all     = container.querySelectorAll( '.core-diet-rec-toggle' );
		var checked = container.querySelectorAll( '.core-diet-rec-toggle:checked' );
		var text = coreDietAdmin.strings.recCounter
			.replace( '%1$d', checked.length )
			.replace( '%2$d', all.length );

		// Calculate savings from checked toggles.
		var estimates  = container._dpEstimates || {};
		var totalReq   = 0;
		var totalKb    = 0;
		var totalQuery = 0;
		checked.forEach( function( cb ) {
			var key = cb.getAttribute( 'data-key' );
			if ( estimates[ key ] ) {
				totalReq   += estimates[ key ][0];
				totalKb    += estimates[ key ][1];
				totalQuery += estimates[ key ][2];
			}
		} );

		if ( totalReq > 0 || totalKb > 0 || totalQuery > 0 ) {
			var parts = [];
			if ( totalReq > 0 ) {
				parts.push( totalReq + ' req' );
			}
			if ( totalKb > 0 ) {
				parts.push( totalKb.toFixed( 1 ) + ' KB' );
			}
			if ( totalQuery > 0 ) {
				parts.push( totalQuery + ' queries' );
			}
			text += coreDietAdmin.strings.recSavings.replace( '%s', parts.join( ', ' ) );
		}

		counter.textContent = text;
	}

	/* ============================
	 * Restore defaults (per-tab)
	 * ============================ */

	/* ============================
	 * Cache: purge and self test
	 * ============================ */

	/**
	 * Purge and test buttons on the Cache tab.
	 *
	 * Both run over AJAX rather than through a redirect. A redirect left the
	 * result in the query string, so the confirmation came back on every reload
	 * of the page, hard refresh included.
	 */
	function initCacheActions() {
		var purgeBtn = document.getElementById( 'core-diet-cache-purge' );
		var testBtn  = document.getElementById( 'core-diet-cache-test' );
		var result   = document.getElementById( 'core-diet-cache-result' );

		if ( ! result || ( ! purgeBtn && ! testBtn ) ) {
			return;
		}

		function show( message, isError ) {
			result.className = 'core-diet-cache-result notice inline ' +
				( isError ? 'notice-warning' : 'notice-success' );

			// Built as a node with textContent rather than as an HTML string:
			// the message comes back from the server and never needs markup.
			var paragraph = document.createElement( 'p' );
			paragraph.textContent = message;
			result.replaceChildren( paragraph );
			result.hidden = false;
		}

		function refreshStats( stats ) {
			if ( ! stats ) {
				return;
			}
			var pages = document.getElementById( 'core-diet-cache-pages' );
			var bytes = document.getElementById( 'core-diet-cache-bytes' );
			if ( pages ) {
				pages.textContent = stats.pages;
			}
			if ( bytes ) {
				bytes.textContent = stats.bytes;
			}
		}

		function run( button, action, extra ) {
			var body = new FormData();
			body.append( 'action', action );
			body.append( 'security', coreDietAdmin.nonce );

			Object.keys( extra || {} ).forEach( function( key ) {
				body.append( key, extra[ key ] );
			} );

			var label = button.textContent;
			button.disabled = true;
			button.textContent = coreDietAdmin.strings.working;
			result.hidden = true;

			fetch( coreDietAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function( response ) { return response.json(); } )
				.then( function( json ) {
					var payload = json && json.data ? json.data : {};
					var message = typeof payload === 'string' ? payload : payload.message;
					show( message || coreDietAdmin.strings.error, ! json.success );
					refreshStats( payload.stats );
				} )
				.catch( function() {
					show( coreDietAdmin.strings.error, true );
				} )
				.finally( function() {
					button.disabled = false;
					button.textContent = label;
				} );
		}

		if ( purgeBtn ) {
			purgeBtn.addEventListener( 'click', function() {
				var input = document.getElementById( 'core-diet-cache-url' );
				var url   = input ? input.value.trim() : '';

				if ( '' === url && ! window.confirm( coreDietAdmin.strings.confirmPurgeAll ) ) {
					return;
				}

				run( purgeBtn, 'core_diet_cache_purge', { cache_url: url } );
			} );
		}

		if ( testBtn ) {
			testBtn.addEventListener( 'click', function() {
				run( testBtn, 'core_diet_cache_test', {} );
			} );
		}

		initCacheSearch();
	}

	/**
	 * Instant search on the purge field: type a title, get its URL.
	 *
	 * Debounced at 400ms with a three character minimum, the same shape as the
	 * search boxes in Vigilant. Copying a permalink by hand is where the wrong
	 * domain and the missing trailing slash come from.
	 */
	function initCacheSearch() {
		var input   = document.getElementById( 'core-diet-cache-url' );
		var results = document.getElementById( 'core-diet-cache-search-results' );

		if ( ! input || ! results ) {
			return;
		}

		var timer   = null;
		var active  = -1;

		function close() {
			results.hidden = true;
			results.replaceChildren();
			input.setAttribute( 'aria-expanded', 'false' );
			active = -1;
		}

		function items() {
			return results.querySelectorAll( '.core-diet-search-item' );
		}

		function highlight( index ) {
			var all = items();
			if ( ! all.length ) {
				return;
			}
			active = ( index + all.length ) % all.length;
			all.forEach( function( item, i ) {
				item.classList.toggle( 'is-active', i === active );
				item.setAttribute( 'aria-selected', i === active ? 'true' : 'false' );
			} );
			all[ active ].scrollIntoView( { block: 'nearest' } );
		}

		function render( list ) {
			results.replaceChildren();

			if ( ! list.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'core-diet-search-empty';
				empty.textContent = coreDietAdmin.strings.searchNoResults;
				results.appendChild( empty );
				results.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
				return;
			}

			list.forEach( function( item ) {
				var row = document.createElement( 'button' );
				row.type = 'button';
				row.className = 'core-diet-search-item';
				row.setAttribute( 'role', 'option' );
				row.setAttribute( 'aria-selected', 'false' );
				row.dataset.url = item.url;

				var title = document.createElement( 'span' );
				title.className = 'core-diet-search-item-title';
				title.textContent = item.label;

				var meta = document.createElement( 'span' );
				meta.className = 'core-diet-search-item-meta';
				meta.textContent = item.type + ' — ' + item.url;

				row.appendChild( title );
				row.appendChild( meta );

				row.addEventListener( 'click', function() {
					input.value = item.url;
					close();
					input.focus();
				} );

				results.appendChild( row );
			} );

			results.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		input.addEventListener( 'input', function() {
			var term = input.value.trim();

			window.clearTimeout( timer );

			// A pasted URL needs no search, and neither does anything shorter
			// than three characters.
			if ( term.length < 3 || /^https?:\/\//i.test( term ) || term.charAt( 0 ) === '/' ) {
				close();
				return;
			}

			timer = window.setTimeout( function() {
				var body = new FormData();
				body.append( 'action', 'core_diet_cache_search' );
				body.append( 'security', coreDietAdmin.nonce );
				body.append( 'term', term );

				fetch( coreDietAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function( response ) { return response.json(); } )
					.then( function( json ) {
						if ( json.success && json.data ) {
							render( json.data.results || [] );
						}
					} )
					.catch( close );
			}, 400 );
		} );

		input.addEventListener( 'keydown', function( e ) {
			if ( results.hidden ) {
				return;
			}
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				highlight( active + 1 );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				highlight( active - 1 );
			} else if ( 'Enter' === e.key && active > -1 ) {
				e.preventDefault();
				items()[ active ].click();
			} else if ( 'Escape' === e.key ) {
				close();
			}
		} );

		document.addEventListener( 'click', function( e ) {
			if ( ! results.hidden && ! results.contains( e.target ) && e.target !== input ) {
				close();
			}
		} );
	}

	function initRestoreDefaults() {
		var restoreBtn = document.getElementById( 'core-diet-restore-defaults' );
		if ( ! restoreBtn || typeof coreDietAdmin === 'undefined' ) {
			return;
		}

		restoreBtn.addEventListener( 'click', function( e ) {
			e.preventDefault();

			// Determine the current active tab.
			var activeLink = document.querySelector( '.core-diet-nav-tab.nav-tab-active' );
			var currentTab = activeLink ? activeLink.getAttribute( 'data-tab' ) : '';

			if ( ! currentTab || currentTab === 'tools' ) {
				return;
			}

			/* eslint-disable no-alert */
			if ( ! confirm( coreDietAdmin.strings.confirmRestore ) ) {
				return;
			}

			restoreBtn.disabled = true;

			var formData = new FormData();
			formData.append( 'action', 'core_diet_restore_tab' );
			formData.append( 'security', coreDietAdmin.nonce );
			formData.append( 'tab', currentTab );

			fetch( coreDietAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
			.then( function( response ) { return response.json(); } )
			.then( function( result ) {
				if ( result.success ) {
					// The server leaves a one-shot notice behind, and the page
					// has to open at the top for it to be seen: browsers put a
					// reload back where it was unless told otherwise.
					if ( 'scrollRestoration' in history ) {
						history.scrollRestoration = 'manual';
					}
					window.scrollTo( 0, 0 );
					window.location.reload();
				} else {
					alert( result.data || coreDietAdmin.strings.error );
					restoreBtn.disabled = false;
				}
			} )
			.catch( function() {
				alert( coreDietAdmin.strings.error );
				restoreBtn.disabled = false;
			} );
		} );
	}

	/* ============================
	 * Section toggle-all buttons
	 * ============================ */

	/**
	 * Toggle all checkboxes within a section.
	 *
	 * Finds all .core-diet-option-card siblings between the clicked
	 * header and the next section header or end of the grid container.
	 */
	function initSectionToggles() {
		var buttons = document.querySelectorAll( '.core-diet-toggle-all' );
		if ( ! buttons.length ) {
			return;
		}

		buttons.forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var header = btn.closest( '.core-diet-section-header' );
				if ( ! header ) {
					return;
				}

				// Collect all option cards until the next section header/title.
				var cards   = [];
				var sibling = header.nextElementSibling;
				while ( sibling ) {
					if ( sibling.classList.contains( 'core-diet-section-header' ) ||
						( sibling.tagName === 'H2' && sibling.classList.contains( 'core-diet-section-title' ) ) ) {
						break;
					}
					if ( sibling.classList.contains( 'core-diet-option-card' ) ) {
						cards.push( sibling );
					}
					sibling = sibling.nextElementSibling;
				}

				if ( ! cards.length ) {
					return;
				}

				// Gather all checkboxes in the section.
				var checkboxes = [];
				cards.forEach( function( card ) {
					var cb = card.querySelector( 'input[type="checkbox"]' );
					if ( cb ) {
						checkboxes.push( cb );
					}
				} );

				if ( ! checkboxes.length ) {
					return;
				}

				// If all checked → uncheck all, otherwise → check all.
				var allChecked = checkboxes.every( function( cb ) { return cb.checked; } );
				checkboxes.forEach( function( cb ) {
					cb.checked = ! allChecked;
				} );
			} );
		} );
	}

	/* ============================
	 * Utilities
	 * ============================ */

	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function escAttr( str ) {
		return String( str ).replace( /[&"'<>]/g, function( m ) {
			return { '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' }[ m ];
		} );
	}

	function addParam( url, key, value ) {
		var u = new URL( url );
		u.searchParams.set( key, value );
		return u.toString();
	}

} )();