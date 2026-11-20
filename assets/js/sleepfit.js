/*
 * SleepFit enhancer.
 *
 * The form is fully usable without this file: every question is rendered and a
 * normal POST produces the same result cards. Here we only add the step-by-step
 * flow and swap the page reload for a fetch to the public REST endpoint.
 *
 * The endpoint is public and does not check a nonce, so the fetch uses
 * same-origin cookies. Omitting them breaks sites whose auth layer redirects
 * cookieless requests (WordPress Playground is one).
 */
( function () {
	'use strict';

	var config = window.sleepfitConfig || {};
	var strings = config.i18n || {};

	function format( template, a, b ) {
		return String( template || '' ).replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) );
	}

	function textFromHtml( html ) {
		var holder = document.createElement( 'div' );
		holder.innerHTML = html || '';
		return ( holder.textContent || '' ).trim();
	}

	document.querySelectorAll( '[data-sleepfit]' ).forEach( function ( root ) {
		var form = root.querySelector( '[data-sleepfit-form]' );
		var steps = Array.prototype.slice.call( root.querySelectorAll( '[data-sleepfit-step]' ) );
		var template = root.querySelector( '[data-sleepfit-template]' );
		var results = root.querySelector( '[data-sleepfit-results]' );
		var status = root.querySelector( '[data-sleepfit-status]' );
		var progress = root.querySelector( '[data-sleepfit-progress]' );
		var back = root.querySelector( '[data-sleepfit-back]' );
		var next = root.querySelector( '[data-sleepfit-next]' );
		var submit = root.querySelector( '[data-sleepfit-submit]' );

		if ( ! form || ! steps.length || ! results || ! template ) {
			return;
		}

		var current = 0;
		var busy = false;

		root.classList.add( 'is-enhanced' );

		function showStep( index, moveFocus ) {
			current = index;

			steps.forEach( function ( step, i ) {
				step.hidden = i !== index;
			} );

			progress.hidden = false;
			progress.textContent = format( strings.progress, index + 1, steps.length );
			back.hidden = index === 0;
			next.hidden = index === steps.length - 1;
			submit.hidden = index !== steps.length - 1;
			status.textContent = '';

			var legend = steps[ index ].querySelector( '.sleepfit__legend' );

			if ( legend && moveFocus ) {
				legend.setAttribute( 'tabindex', '-1' );
				legend.focus( { preventScroll: true } );
			}
		}

		function firstMissingStep() {
			for ( var i = 0; i < steps.length; i++ ) {
				if ( ! steps[ i ].querySelector( 'input:checked' ) ) {
					return i;
				}
			}

			return -1;
		}

		function collect() {
			var answers = {};

			steps.forEach( function ( step ) {
				var checked = step.querySelector( 'input:checked' );

				if ( ! checked || ! checked.name ) {
					return;
				}

				var match = checked.name.match( /\[([^\]]+)\]/ );

				if ( match ) {
					answers[ match[ 1 ] ] = checked.value;
				}
			} );

			return answers;
		}

		function buildCard( match ) {
			var card = template.content.firstElementChild.cloneNode( true );
			var media = card.querySelector( '[data-slot="media"]' );
			var score = card.querySelector( '[data-slot="score"]' );
			var band = card.querySelector( '[data-slot="band"]' );
			var link = card.querySelector( '[data-slot="link"]' );
			var price = card.querySelector( '[data-slot="price"]' );
			var blurb = card.querySelector( '[data-slot="blurb"]' );
			var reasons = card.querySelector( '[data-slot="reasons"]' );

			if ( media && match.image && match.image.url ) {
				var img = document.createElement( 'img' );
				img.src = match.image.url;
				img.alt = match.image.alt || match.name || '';
				img.width = match.image.width || 600;
				img.height = match.image.height || 450;
				img.loading = 'lazy';
				img.decoding = 'async';
				media.replaceChildren( img );
			}

			if ( score ) {
				score.textContent = String( match.score ) + '%';
			}

			if ( band ) {
				band.textContent = match.band || '';
			}

			if ( link ) {
				link.href = match.url || '#';
				link.textContent = match.name || '';
			}

			if ( price ) {
				price.textContent = textFromHtml( match.price_html );
			}

			if ( blurb ) {
				if ( match.blurb ) {
					blurb.textContent = match.blurb;
					blurb.hidden = false;
				} else {
					blurb.hidden = true;
				}
			}

			if ( reasons ) {
				reasons.replaceChildren();

				( match.reasons || [] ).forEach( function ( reason ) {
					var li = document.createElement( 'li' );
					li.textContent = reason;
					reasons.appendChild( li );
				} );
			}

			return card;
		}

		function render( matches ) {
			results.replaceChildren();

			if ( ! matches.length ) {
				status.textContent = strings.empty || '';
				return;
			}

			var head = document.createElement( 'div' );
			head.className = 'sleepfit__results-head';

			var heading = document.createElement( 'h3' );
			heading.className = 'sleepfit__results-title';
			heading.textContent = strings.results || '';

			var note = document.createElement( 'p' );
			note.className = 'sleepfit__results-note';
			note.textContent = strings.note || '';

			head.appendChild( heading );
			head.appendChild( note );

			var cards = document.createElement( 'div' );
			cards.className = 'sleepfit__cards';
			matches.forEach( function ( match ) {
				cards.appendChild( buildCard( match ) );
			} );

			var again = document.createElement( 'a' );
			again.className = 'sleepfit__again';
			again.href = window.location.pathname + window.location.search;
			again.textContent = strings.again || '';

			results.appendChild( head );
			results.appendChild( cards );
			results.appendChild( again );
			results.hidden = false;
			form.hidden = true;
			status.textContent = '';

			results.focus( { preventScroll: true } );

			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			if ( results.scrollIntoView ) {
				results.scrollIntoView( { behavior: reduce ? 'auto' : 'smooth', block: 'start' } );
			}
		}

		function send() {
			if ( busy ) {
				return;
			}

			var missing = firstMissingStep();

			if ( missing !== -1 ) {
				showStep( missing, true );
				status.textContent = strings.required || '';
				return;
			}

			busy = true;
			submit.disabled = true;
			status.textContent = strings.loading || '';

			fetch( config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json'
				},
				body: JSON.stringify( { answers: collect() } )
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}

					return response.json();
				} )
				.then( function ( payload ) {
					render( payload.matches || [] );
				} )
				.catch( function () {
					status.textContent = strings.error || '';
				} )
				.finally( function () {
					busy = false;
					submit.disabled = false;
				} );
		}

		next.addEventListener( 'click', function () {
			if ( ! steps[ current ].querySelector( 'input:checked' ) ) {
				status.textContent = strings.required || '';
				return;
			}

			if ( current < steps.length - 1 ) {
				showStep( current + 1, true );
			}
		} );

		back.addEventListener( 'click', function () {
			if ( current > 0 ) {
				showStep( current - 1, true );
			}
		} );

		form.addEventListener( 'change', function () {
			status.textContent = '';
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			send();
		} );

		showStep( 0, false );
	} );
} )();
