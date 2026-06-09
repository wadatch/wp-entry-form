/**
 * Gutenberg ブロック「WP Entry Form」のエディタスクリプト。
 *
 * フォーム一覧（REST: wpef/v1/forms）から表示するフォームを選ぶ。
 * 保存は動的（save: null）で、フロントは render.php（ショートコード）でサーバ描画する。
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { SelectControl, Placeholder } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

registerBlockType( 'wpef/entry-form', {
	edit( { attributes, setAttributes } ) {
		const [ forms, setForms ] = useState( [] );

		useEffect( () => {
			apiFetch( { path: 'wpef/v1/forms' } )
				.then( ( data ) => setForms( Array.isArray( data ) ? data : [] ) )
				.catch( () => setForms( [] ) );
		}, [] );

		const options = [ { label: __( '— フォームを選択 —', 'wp-entry-form' ), value: 0 } ].concat(
			forms.map( ( f ) => ( { label: `${ f.title } (#${ f.id })`, value: f.id } ) )
		);

		return (
			<div { ...useBlockProps() }>
				<Placeholder
					icon="feedback"
					label={ __( 'WP Entry Form', 'wp-entry-form' ) }
					instructions={ __( '表示するフォームを選択してください。', 'wp-entry-form' ) }
				>
					<SelectControl
						value={ attributes.formId }
						options={ options }
						onChange={ ( v ) => setAttributes( { formId: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
				</Placeholder>
			</div>
		);
	},
	save() {
		return null;
	},
} );
