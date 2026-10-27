import { Button, Tooltip } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';
import { useEventsSettings } from './EventsSettingsContext';

/**
 * Filter keys accepted by the filtered CSV export.
 * Pagination and response-shaping keys are left out: the export
 * walks every matching page on the server.
 */
const EXPORT_QUERY_KEYS = [
	'search',
	'date_from',
	'date_to',
	'dates',
	'loglevels',
	'loggers',
	'messages',
	'user',
	'users',
	'initiator',
	'ip_address',
	'context_filters',
	'metadata_search',
	'ai_only',
	'include_sticky',
	'exclude_search',
	'exclude_loglevels',
	'exclude_loggers',
	'exclude_messages',
	'exclude_user',
	'exclude_users',
	'exclude_initiator',
];

/**
 * Copy the current log-page filters, dropping empty values.
 *
 * @param {Object} eventsQueryParams Params used by the events REST request.
 * @return {Object} Params to post with the export.
 */
function pickExportQueryArgs( eventsQueryParams ) {
	const args = {};

	EXPORT_QUERY_KEYS.forEach( ( key ) => {
		const value = eventsQueryParams?.[ key ];

		if ( value === undefined || value === null || value === '' ) {
			return;
		}

		if ( Array.isArray( value ) && value.length === 0 ) {
			return;
		}

		if (
			typeof value === 'object' &&
			! Array.isArray( value ) &&
			Object.keys( value ).length === 0
		) {
			return;
		}

		args[ key ] = value;
	} );

	return args;
}

/**
 * Add one hidden input to a form.
 *
 * @param {HTMLFormElement} form
 * @param {string}          name
 * @param {string}          value
 */
function appendHiddenInput( form, name, value ) {
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;
	input.value = value;
	form.appendChild( input );
}

/**
 * Export button in the log control bar.
 *
 * Posts the current filters to admin-post.php. The server returns a CSV
 * of every matching event, with headers. The full-history export under
 * Tools is a separate form and is not used here.
 *
 * @param {Object} props
 * @param {Object} props.eventsQueryParams Current events REST query params.
 */
export function ExportButton( { eventsQueryParams = {} } ) {
	const [ isExporting, setIsExporting ] = useState( false );
	const { searchOptionsLoaded } = useEventsSettings();

	const handleClick = () => {
		const config = window.simpleHistoryFilteredExport;
		if (
			! config?.url ||
			! config?.nonce ||
			! config?.action ||
			isExporting
		) {
			return;
		}

		setIsExporting( true );

		const form = document.createElement( 'form' );
		form.method = 'post';
		form.action = config.url;
		form.acceptCharset = 'UTF-8';

		appendHiddenInput( form, 'action', config.action );
		appendHiddenInput( form, '_wpnonce', config.nonce );
		appendHiddenInput(
			form,
			'query_args',
			JSON.stringify( pickExportQueryArgs( eventsQueryParams ) )
		);

		document.body.appendChild( form );
		form.submit();

		window.setTimeout( () => {
			form.remove();
			setIsExporting( false );
		}, 1500 );
	};

	return (
		<Tooltip
			text={ __(
				'Download the events matching the current filters as CSV',
				'simple-history'
			) }
			delay={ 400 }
		>
			<Button
				icon={ download }
				variant="tertiary"
				size="compact"
				onClick={ handleClick }
				disabled={ ! searchOptionsLoaded || isExporting }
				aria-busy={ isExporting }
				className="sh-ControlBarButton sh-ControlBarButton--export"
			>
				{ __( 'Export CSV', 'simple-history' ) }
			</Button>
		</Tooltip>
	);
}
