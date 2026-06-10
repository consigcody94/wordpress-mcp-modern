/**
 * React settings app for WordPress MCP (Modern).
 *
 * Uses WordPress's bundled React (wp-element) and admin components
 * (wp-components) via createElement — no JSX, no build step. Mounts over the
 * server-rendered form (#wpmcp-legacy-settings), which remains the no-JS
 * fallback. Settings persist via /wpmcp/v1/settings; tokens via /jwt-auth/v1.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.components || ! wp.apiFetch ) {
		return; // Leave the legacy form in place.
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Fragment = wp.element.Fragment;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;

	var Button = wp.components.Button;
	var Card = wp.components.Card;
	var CardBody = wp.components.CardBody;
	var CardHeader = wp.components.CardHeader;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var Spinner = wp.components.Spinner;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	var boot = window.wpmcpSettingsBoot || {};
	var SETTINGS_PATH = boot.settingsPath || '/wpmcp/v1/settings';
	var TOKENS_BASE = boot.tokensBase || '/jwt-auth/v1';

	var SETTING_FIELDS = [
		{
			key: 'enabled',
			label: __( 'Enable MCP', 'wordpress-mcp-modern' ),
			help: __( 'Master switch for the whole MCP surface.', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_create_tools',
			label: __( 'Enable create tools', 'wordpress-mcp-modern' ),
			help: __( 'Allow tools that create content (posts, media, products…).', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_update_tools',
			label: __( 'Enable update tools', 'wordpress-mcp-modern' ),
			help: __( 'Allow tools that modify existing content.', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_delete_tools',
			label: __( 'Enable delete tools (destructive)', 'wordpress-mcp-modern' ),
			help: __( 'Allow tools that delete content. Off = nothing can be deleted via MCP.', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_rest_api_crud_tools',
			label: __( 'Experimental: REST-CRUD mode', 'wordpress-mcp-modern' ),
			help: __( 'Replace the curated toolset with three generic "call any REST route" tools.', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_audit_log',
			label: __( 'Audit log', 'wordpress-mcp-modern' ),
			help: __( 'Record every tool call (time, user, tool, outcome) — last 100 shown below.', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_rate_limiting',
			label: __( 'Rate limiting', 'wordpress-mcp-modern' ),
			help: __( 'Cap tool calls per user per minute (default 60, filterable).', 'wordpress-mcp-modern' ),
		},
		{
			key: 'enable_oauth',
			label: __( 'Experimental: OAuth 2.1 authorization', 'wordpress-mcp-modern' ),
			help: __( 'Let MCP clients connect via OAuth (PKCE + dynamic client registration) instead of pasting tokens.', 'wordpress-mcp-modern' ),
		},
	];

	var EXPIRY_OPTIONS = [
		{ value: '3600', label: __( '1 hour', 'wordpress-mcp-modern' ) },
		{ value: '21600', label: __( '6 hours', 'wordpress-mcp-modern' ) },
		{ value: '86400', label: __( '1 day', 'wordpress-mcp-modern' ) },
		{ value: '604800', label: __( '7 days', 'wordpress-mcp-modern' ) },
		{ value: '2592000', label: __( '30 days', 'wordpress-mcp-modern' ) },
	];

	function formatDate( seconds ) {
		return new Date( seconds * 1000 ).toLocaleString();
	}

	function App() {
		var dataState = useState( null );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];

		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];

		var noticeState = useState( null ); // { status, message }
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		var filterState = useState( '' );
		var filter = filterState[ 0 ];
		var setFilter = filterState[ 1 ];

		var tokensState = useState( [] );
		var tokens = tokensState[ 0 ];
		var setTokens = tokensState[ 1 ];

		var newTokenState = useState( null );
		var newToken = newTokenState[ 0 ];
		var setNewToken = newTokenState[ 1 ];

		var expiresState = useState( '3600' );
		var expiresIn = expiresState[ 0 ];
		var setExpiresIn = expiresState[ 1 ];

		var auditState = useState( null ); // { enabled, entries }
		var audit = auditState[ 0 ];
		var setAudit = auditState[ 1 ];

		function fail( error ) {
			setNotice( {
				status: 'error',
				message: ( error && error.message ) || __( 'Request failed.', 'wordpress-mcp-modern' ),
			} );
		}

		function refreshTokens() {
			apiFetch( { path: TOKENS_BASE + '/tokens' } )
				.then( function ( response ) {
					setTokens( ( response && response.tokens ) || [] );
				} )
				.catch( fail );
		}

		function refreshAudit() {
			apiFetch( { path: SETTINGS_PATH.replace( /\/settings$/, '/audit' ) } )
				.then( setAudit )
				.catch( function () {
					setAudit( null );
				} );
		}

		useEffect( function () {
			apiFetch( { path: SETTINGS_PATH } ).then( setData ).catch( fail );
			refreshTokens();
			refreshAudit();
		}, [] );

		if ( ! data ) {
			return el(
				'div',
				{ style: { padding: '2em 0' } },
				notice
					? el( Notice, { status: notice.status, isDismissible: false }, notice.message )
					: el( Spinner, null )
			);
		}

		function setSetting( key, value ) {
			var settings = Object.assign( {}, data.settings );
			settings[ key ] = value;
			setData( Object.assign( {}, data, { settings: settings } ) );
		}

		function setToolEnabled( tool, enabled ) {
			var tools = data.tools.map( function ( entry ) {
				return entry.tool === tool ? Object.assign( {}, entry, { enabled: enabled } ) : entry;
			} );
			setData( Object.assign( {}, data, { tools: tools } ) );
		}

		function setAllVisible( enabled, visibleTools ) {
			var visible = {};
			visibleTools.forEach( function ( entry ) {
				visible[ entry.tool ] = true;
			} );
			var tools = data.tools.map( function ( entry ) {
				return visible[ entry.tool ] ? Object.assign( {}, entry, { enabled: enabled } ) : entry;
			} );
			setData( Object.assign( {}, data, { tools: tools } ) );
		}

		function save() {
			setSaving( true );
			setNotice( null );
			var toolStates = {};
			data.tools.forEach( function ( entry ) {
				toolStates[ entry.tool ] = entry.enabled;
			} );
			apiFetch( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: { settings: data.settings, tool_states: toolStates },
			} )
				.then( function ( response ) {
					setData( response );
					setNotice( { status: 'success', message: __( 'Settings saved.', 'wordpress-mcp-modern' ) } );
				} )
				.catch( fail )
				.finally( function () {
					setSaving( false );
				} );
		}

		function generateToken() {
			setNotice( null );
			apiFetch( {
				path: TOKENS_BASE + '/token',
				method: 'POST',
				data: { expires_in: parseInt( expiresIn, 10 ) },
			} )
				.then( function ( response ) {
					setNewToken( response && response.token );
					refreshTokens();
				} )
				.catch( fail );
		}

		function revokeToken( jti ) {
			setNotice( null );
			apiFetch( { path: TOKENS_BASE + '/revoke', method: 'POST', data: { jti: jti } } )
				.then( refreshTokens )
				.catch( fail );
		}

		var visibleTools = data.tools.filter( function ( entry ) {
			return ! filter || entry.tool.indexOf( filter.toLowerCase() ) !== -1;
		} );

		return el(
			Fragment,
			null,
			notice &&
				el(
					Notice,
					{
						status: notice.status,
						onRemove: function () {
							setNotice( null );
						},
					},
					notice.message
				),

			el(
				'p',
				null,
				__( 'MCP endpoint:', 'wordpress-mcp-modern' ),
				' ',
				el( 'code', null, data.endpoint || boot.endpoint || '' )
			),

			// --- General settings ---
			el(
				Card,
				{ style: { marginBottom: '1.5em' } },
				el( CardHeader, null, el( 'strong', null, __( 'General', 'wordpress-mcp-modern' ) ) ),
				el(
					CardBody,
					null,
					SETTING_FIELDS.map( function ( field ) {
						return el( ToggleControl, {
							key: field.key,
							__nextHasNoMarginBottom: true,
							label: field.label,
							help: field.help,
							checked: !! data.settings[ field.key ],
							onChange: function ( value ) {
								setSetting( field.key, value );
							},
						} );
					} )
				)
			),

			// --- Per-tool toggles ---
			el(
				Card,
				{ style: { marginBottom: '1.5em' } },
				el( CardHeader, null, el( 'strong', null, __( 'Tools', 'wordpress-mcp-modern' ) ) ),
				el(
					CardBody,
					null,
					el(
						'p',
						{ className: 'description' },
						__( 'Individually disable tools. Tools are also gated by the create/update/delete settings above.', 'wordpress-mcp-modern' )
					),
					el( TextControl, {
						__nextHasNoMarginBottom: true,
						placeholder: __( 'Filter tools…', 'wordpress-mcp-modern' ),
						value: filter,
						onChange: setFilter,
					} ),
					el(
						'p',
						null,
						el(
							Button,
							{
								variant: 'secondary',
								onClick: function () {
									setAllVisible( true, visibleTools );
								},
							},
							__( 'Enable shown', 'wordpress-mcp-modern' )
						),
						' ',
						el(
							Button,
							{
								variant: 'secondary',
								onClick: function () {
									setAllVisible( false, visibleTools );
								},
							},
							__( 'Disable shown', 'wordpress-mcp-modern' )
						)
					),
					el(
						'table',
						{ className: 'widefat striped' },
						el(
							'thead',
							null,
							el(
								'tr',
								null,
								el( 'th', null, __( 'Enabled', 'wordpress-mcp-modern' ) ),
								el( 'th', null, __( 'Tool', 'wordpress-mcp-modern' ) ),
								el( 'th', null, __( 'Type', 'wordpress-mcp-modern' ) )
							)
						),
						el(
							'tbody',
							null,
							visibleTools.map( function ( entry ) {
								return el(
									'tr',
									{ key: entry.tool },
									el(
										'td',
										null,
										el( CheckboxControl, {
											__nextHasNoMarginBottom: true,
											checked: entry.enabled,
											onChange: function ( value ) {
												setToolEnabled( entry.tool, value );
											},
										} )
									),
									el( 'td', null, el( 'code', null, entry.tool ) ),
									el( 'td', null, entry.type )
								);
							} )
						)
					)
				)
			),

			el(
				'p',
				null,
				el(
					Button,
					{ variant: 'primary', isBusy: saving, disabled: saving, onClick: save },
					saving ? __( 'Saving…', 'wordpress-mcp-modern' ) : __( 'Save changes', 'wordpress-mcp-modern' )
				)
			),

			// --- JWT tokens ---
			el(
				Card,
				null,
				el( CardHeader, null, el( 'strong', null, __( 'Authentication tokens (JWT)', 'wordpress-mcp-modern' ) ) ),
				el(
					CardBody,
					null,
					newToken &&
						el(
							Notice,
							{
								status: 'info',
								onRemove: function () {
									setNewToken( null );
								},
							},
							el( 'p', null, __( 'New token (copy now, it will not be shown again):', 'wordpress-mcp-modern' ) ),
							el( 'textarea', {
								readOnly: true,
								rows: 3,
								style: { width: '100%' },
								value: newToken,
								onFocus: function ( event ) {
									event.target.select();
								},
							} )
						),
					el(
						'div',
						{ style: { display: 'flex', gap: '8px', alignItems: 'flex-end', marginBottom: '1em' } },
						el( SelectControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Expires in', 'wordpress-mcp-modern' ),
							value: expiresIn,
							options: EXPIRY_OPTIONS,
							onChange: setExpiresIn,
						} ),
						el( Button, { variant: 'secondary', onClick: generateToken }, __( 'Generate token', 'wordpress-mcp-modern' ) )
					),
					el(
						'table',
						{ className: 'widefat striped' },
						el(
							'thead',
							null,
							el(
								'tr',
								null,
								el( 'th', null, __( 'User', 'wordpress-mcp-modern' ) ),
								el( 'th', null, __( 'Issued', 'wordpress-mcp-modern' ) ),
								el( 'th', null, __( 'Expires', 'wordpress-mcp-modern' ) ),
								el( 'th', null, __( 'Status', 'wordpress-mcp-modern' ) ),
								el( 'th', null )
							)
						),
						el(
							'tbody',
							null,
							tokens.length === 0 &&
								el(
									'tr',
									null,
									el( 'td', { colSpan: 5 }, __( 'No active tokens.', 'wordpress-mcp-modern' ) )
								),
							tokens.map( function ( token ) {
								return el(
									'tr',
									{ key: token.jti },
									el( 'td', null, String( token.user_id ) ),
									el( 'td', null, formatDate( token.issued_at ) ),
									el( 'td', null, formatDate( token.expires_at ) ),
									el(
										'td',
										null,
										token.revoked
											? __( 'revoked', 'wordpress-mcp-modern' )
											: __( 'active', 'wordpress-mcp-modern' )
									),
									el(
										'td',
										null,
										! token.revoked &&
											el(
												Button,
												{
													variant: 'secondary',
													isDestructive: true,
													size: 'small',
													onClick: function () {
														revokeToken( token.jti );
													},
												},
												__( 'Revoke', 'wordpress-mcp-modern' )
											)
									)
								);
							} )
						)
					)
				)
			),

			// --- Audit log ---
			audit &&
				audit.enabled &&
				el(
					Card,
					{ style: { marginTop: '1.5em' } },
					el(
						CardHeader,
						null,
						el( 'strong', null, __( 'Recent tool calls', 'wordpress-mcp-modern' ) ),
						el(
							Button,
							{ variant: 'tertiary', onClick: refreshAudit },
							__( 'Refresh', 'wordpress-mcp-modern' )
						)
					),
					el(
						CardBody,
						null,
						el(
							'table',
							{ className: 'widefat striped' },
							el(
								'thead',
								null,
								el(
									'tr',
									null,
									el( 'th', null, __( 'Time', 'wordpress-mcp-modern' ) ),
									el( 'th', null, __( 'User', 'wordpress-mcp-modern' ) ),
									el( 'th', null, __( 'Tool', 'wordpress-mcp-modern' ) ),
									el( 'th', null, __( 'Result', 'wordpress-mcp-modern' ) )
								)
							),
							el(
								'tbody',
								null,
								audit.entries.length === 0 &&
									el(
										'tr',
										null,
										el( 'td', { colSpan: 4 }, __( 'No tool calls recorded yet.', 'wordpress-mcp-modern' ) )
									),
								audit.entries.map( function ( entry, index ) {
									return el(
										'tr',
										{ key: index },
										el( 'td', null, formatDate( entry.time ) ),
										el( 'td', null, String( entry.user_id ) ),
										el( 'td', null, el( 'code', null, entry.tool ) ),
										el(
											'td',
											null,
											entry.ok
												? '✓ ' + __( 'ok', 'wordpress-mcp-modern' )
												: '✗ ' + __( 'error', 'wordpress-mcp-modern' )
										)
									);
								} )
							)
						)
					)
				)
		);
	}

	function mount() {
		var node = document.getElementById( 'wpmcp-settings-app' );
		if ( ! node ) {
			return;
		}
		var legacy = document.getElementById( 'wpmcp-legacy-settings' );
		if ( legacy ) {
			legacy.style.display = 'none';
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( node ).render( el( App, null ) );
		} else {
			wp.element.render( el( App, null ), node );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp );
