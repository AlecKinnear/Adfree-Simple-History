import { MenuItem } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { check, link } from '@wordpress/icons';

// WordPress' success/alert green, matching admin notice-success.
const COPY_SUCCESS_COLOR = '#00a32a';

export function EventCopyLinkMenuItem( { event } ) {
	const permalink = event.permalink;
	const copyText = __( 'Copy link to event details', 'simple-history' );
	const copiedText = __( 'Link copied to clipboard', 'simple-history' );

	const [ dynamicCopyText, setDynamicCopyText ] = useState( copyText );

	const ref = useCopyToClipboard( permalink, () => {
		setDynamicCopyText( copiedText );
		setTimeout( () => {
			setDynamicCopyText( copyText );
		}, 2000 );
	} );

	const isCopied = dynamicCopyText === copiedText;

	return (
		<MenuItem icon={ isCopied ? check : link } ref={ ref }>
			<span
				style={ isCopied ? { color: COPY_SUCCESS_COLOR } : undefined }
			>
				{ dynamicCopyText }
			</span>
		</MenuItem>
	);
}
