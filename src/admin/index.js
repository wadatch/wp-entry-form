/**
 * フォームビルダー（管理画面）の React UI。
 *
 * window.wpefBuilder（PHP の wp_localize_script）から初期のフィールド・設定・型一覧を読み、
 * フィールドの追加/編集/削除/並べ替えと設定タブ（基本/メール/スパム）を提供する。
 * 状態は隠し input（name="wpef_fields" / "wpef_settings"）に JSON で書き出し、
 * 周囲の PHP フォームの標準 POST で保存される（ビルド: @wordpress/scripts）。
 */
import { createRoot, useState, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	TabPanel,
	Flex,
	FlexItem,
	FlexBlock,
	Notice,
} from '@wordpress/components';

const data = window.wpefBuilder || {};
const FIELD_TYPES = data.fieldTypes || [];

function typeMeta( type ) {
	return FIELD_TYPES.find( ( t ) => t.type === type ) || { input: true, hasOptions: false, multiple: false, display: false };
}

function defaultSettings( raw ) {
	const s = raw && typeof raw === 'object' ? raw : {};
	const messages = s.messages || {};
	const admin = s.admin_notification || {};
	const auto = s.autoresponder || {};
	const spam = s.spam || {};
	const rate = spam.rate_limit || {};
	return {
		confirmation_screen: !! s.confirmation_screen,
		messages: {
			success: messages.success || __( 'ご応募ありがとうございました。', 'wp-entry-form' ),
			submit_button: messages.submit_button || __( '送信する', 'wp-entry-form' ),
			confirm_button: messages.confirm_button || __( '入力内容を確認', 'wp-entry-form' ),
			back_button: messages.back_button || __( '戻る', 'wp-entry-form' ),
		},
		redirect_url: s.redirect_url || '',
		admin_notification: {
			enabled: admin.enabled !== undefined ? !! admin.enabled : true,
			to: Array.isArray( admin.to ) ? admin.to.join( ', ' ) : ( admin.to || '' ),
			subject: admin.subject || __( '新しい応募がありました', 'wp-entry-form' ),
			body: admin.body || '{all_fields}',
		},
		autoresponder: {
			enabled: !! auto.enabled,
			to_field: auto.to_field || '',
			from_name: auto.from_name || '',
			from_email: auto.from_email || '',
			subject: auto.subject || __( '応募を受け付けました', 'wp-entry-form' ),
			body: auto.body || '',
		},
		spam: {
			honeypot: spam.honeypot !== undefined ? !! spam.honeypot : true,
			rate_limit: {
				enabled: !! rate.enabled,
				max: rate.max || 3,
				window: rate.window || 600,
			},
		},
	};
}

function newField( type ) {
	const meta = typeMeta( type );
	const f = { type, label: typeMeta( type ).label || type };
	if ( meta.input ) {
		f.key = '';
		f.required = false;
		f.placeholder = '';
		f.help = '';
		f.default = '';
		if ( meta.hasOptions ) {
			f.options = [ { label: __( '選択肢1', 'wp-entry-form' ), value: 'option_1' } ];
		}
		if ( type === 'file' ) {
			f.file = { max_size_mb: 5, accept: [ 'pdf', 'jpg', 'jpeg', 'png' ] };
		}
		f.validation = {};
	}
	return f;
}

/* ------------------------------------------------------------------ */
/* オプション編集                                                      */
/* ------------------------------------------------------------------ */
function OptionsEditor( { options, onChange } ) {
	const list = options || [];
	const update = ( i, patch ) => {
		const next = list.map( ( o, idx ) => ( idx === i ? { ...o, ...patch } : o ) );
		onChange( next );
	};
	return (
		<div className="wpef-options-editor">
			<p><strong>{ __( '選択肢', 'wp-entry-form' ) }</strong></p>
			{ list.map( ( opt, i ) => (
				<Flex key={ i } align="flex-end" gap={ 2 } style={ { marginBottom: 6 } }>
					<FlexBlock>
						<TextControl
							label={ __( 'ラベル', 'wp-entry-form' ) }
							value={ opt.label || '' }
							onChange={ ( v ) => update( i, { label: v } ) }
							__nextHasNoMarginBottom
						/>
					</FlexBlock>
					<FlexBlock>
						<TextControl
							label={ __( '値', 'wp-entry-form' ) }
							value={ opt.value || '' }
							onChange={ ( v ) => update( i, { value: v } ) }
							__nextHasNoMarginBottom
						/>
					</FlexBlock>
					<FlexItem>
						<Button isDestructive variant="secondary" onClick={ () => onChange( list.filter( ( o, idx ) => idx !== i ) ) }>
							{ __( '削除', 'wp-entry-form' ) }
						</Button>
					</FlexItem>
				</Flex>
			) ) }
			<Button variant="secondary" onClick={ () => onChange( [ ...list, { label: '', value: '' } ] ) }>
				{ __( '選択肢を追加', 'wp-entry-form' ) }
			</Button>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* フィールド1件の編集                                                 */
/* ------------------------------------------------------------------ */
function FieldCard( { field, index, total, onChange, onRemove, onMove, dragHandlers } ) {
	const meta = typeMeta( field.type );
	const patch = ( p ) => onChange( index, { ...field, ...p } );
	const patchValidation = ( p ) => onChange( index, { ...field, validation: { ...( field.validation || {} ), ...p } } );

	return (
		<Card
			className="wpef-field-card"
			draggable
			onDragStart={ ( e ) => dragHandlers.onDragStart( e, index ) }
			onDragOver={ ( e ) => dragHandlers.onDragOver( e, index ) }
			onDrop={ ( e ) => dragHandlers.onDrop( e, index ) }
			style={ { marginBottom: 12 } }
		>
			<CardHeader>
				<Flex>
					<FlexBlock>
						<span className="wpef-drag-handle" title={ __( 'ドラッグで並べ替え', 'wp-entry-form' ) } style={ { cursor: 'grab', marginRight: 8 } }>⠿</span>
						<strong>{ meta.label || field.type }</strong>
						{ field.key ? <code style={ { marginLeft: 8 } }>{ field.key }</code> : null }
					</FlexBlock>
					<FlexItem>
						<Button size="small" variant="tertiary" disabled={ index === 0 } onClick={ () => onMove( index, -1 ) }>↑</Button>
						<Button size="small" variant="tertiary" disabled={ index === total - 1 } onClick={ () => onMove( index, 1 ) }>↓</Button>
						<Button size="small" isDestructive variant="tertiary" onClick={ () => onRemove( index ) }>{ __( '削除', 'wp-entry-form' ) }</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				<TextControl
					label={ meta.display ? __( 'テキスト', 'wp-entry-form' ) : __( 'ラベル', 'wp-entry-form' ) }
					value={ field.label || '' }
					onChange={ ( v ) => patch( { label: v } ) }
					__nextHasNoMarginBottom
				/>

				{ meta.input && (
					<Fragment>
						<TextControl
							label={ __( 'フィールドキー（英数_、空なら自動）', 'wp-entry-form' ) }
							value={ field.key || '' }
							onChange={ ( v ) => patch( { key: v } ) }
							help={ __( 'メール差し込みや CSV の列名に使われます。', 'wp-entry-form' ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( '必須', 'wp-entry-form' ) }
							checked={ !! field.required }
							onChange={ ( v ) => patch( { required: v } ) }
							__nextHasNoMarginBottom
						/>

						{ meta.hasOptions && (
							<OptionsEditor options={ field.options || [] } onChange={ ( opts ) => patch( { options: opts } ) } />
						) }

						{ field.type !== 'consent' && ! meta.hasOptions && field.type !== 'file' && (
							<TextControl
								label={ __( 'プレースホルダ', 'wp-entry-form' ) }
								value={ field.placeholder || '' }
								onChange={ ( v ) => patch( { placeholder: v } ) }
								__nextHasNoMarginBottom
							/>
						) }

						{ field.type === 'number' && (
							<Flex gap={ 2 }>
								<FlexBlock>
									<TextControl type="number" label={ __( '最小値', 'wp-entry-form' ) } value={ field.validation?.min ?? '' } onChange={ ( v ) => patchValidation( { min: v } ) } __nextHasNoMarginBottom />
								</FlexBlock>
								<FlexBlock>
									<TextControl type="number" label={ __( '最大値', 'wp-entry-form' ) } value={ field.validation?.max ?? '' } onChange={ ( v ) => patchValidation( { max: v } ) } __nextHasNoMarginBottom />
								</FlexBlock>
							</Flex>
						) }

						{ ( field.type === 'text' || field.type === 'textarea' ) && (
							<TextControl type="number" label={ __( '最大文字数', 'wp-entry-form' ) } value={ field.validation?.max_length ?? '' } onChange={ ( v ) => patchValidation( { max_length: v } ) } __nextHasNoMarginBottom />
						) }

						{ field.type === 'file' && (
							<Flex gap={ 2 }>
								<FlexBlock>
									<TextControl
										label={ __( '許可拡張子（カンマ区切り）', 'wp-entry-form' ) }
										value={ ( field.file?.accept || [] ).join( ', ' ) }
										onChange={ ( v ) => patch( { file: { ...( field.file || {} ), accept: v.split( ',' ).map( ( s ) => s.trim().replace( /^\./, '' ) ).filter( Boolean ) } } ) }
										__nextHasNoMarginBottom
									/>
								</FlexBlock>
								<FlexItem>
									<TextControl type="number" label={ __( '最大MB', 'wp-entry-form' ) } value={ field.file?.max_size_mb ?? 5 } onChange={ ( v ) => patch( { file: { ...( field.file || {} ), max_size_mb: v } } ) } __nextHasNoMarginBottom />
								</FlexItem>
							</Flex>
						) }

						<TextControl
							label={ __( '補足説明', 'wp-entry-form' ) }
							value={ field.help || '' }
							onChange={ ( v ) => patch( { help: v } ) }
							__nextHasNoMarginBottom
						/>
					</Fragment>
				) }
			</CardBody>
		</Card>
	);
}

/* ------------------------------------------------------------------ */
/* 設定タブ                                                            */
/* ------------------------------------------------------------------ */
function SettingsBasic( { settings, set } ) {
	const m = settings.messages;
	const setMsg = ( p ) => set( { messages: { ...m, ...p } } );
	return (
		<Fragment>
			<ToggleControl label={ __( '確認画面を表示する', 'wp-entry-form' ) } checked={ settings.confirmation_screen } onChange={ ( v ) => set( { confirmation_screen: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '送信ボタンの文言', 'wp-entry-form' ) } value={ m.submit_button } onChange={ ( v ) => setMsg( { submit_button: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '確認ボタンの文言', 'wp-entry-form' ) } value={ m.confirm_button } onChange={ ( v ) => setMsg( { confirm_button: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '戻るボタンの文言', 'wp-entry-form' ) } value={ m.back_button } onChange={ ( v ) => setMsg( { back_button: v } ) } __nextHasNoMarginBottom />
			<TextareaControl label={ __( '完了メッセージ', 'wp-entry-form' ) } value={ m.success } onChange={ ( v ) => setMsg( { success: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '完了後リダイレクト URL（任意）', 'wp-entry-form' ) } value={ settings.redirect_url } onChange={ ( v ) => set( { redirect_url: v } ) } __nextHasNoMarginBottom />
		</Fragment>
	);
}

function SettingsMail( { settings, set, fieldKeys } ) {
	const a = settings.admin_notification;
	const r = settings.autoresponder;
	const setA = ( p ) => set( { admin_notification: { ...a, ...p } } );
	const setR = ( p ) => set( { autoresponder: { ...r, ...p } } );
	return (
		<Fragment>
			<h3>{ __( '管理者通知メール', 'wp-entry-form' ) }</h3>
			<ToggleControl label={ __( '有効にする', 'wp-entry-form' ) } checked={ a.enabled } onChange={ ( v ) => setA( { enabled: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '宛先（カンマ区切り、空なら管理者メール）', 'wp-entry-form' ) } value={ a.to } onChange={ ( v ) => setA( { to: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '件名', 'wp-entry-form' ) } value={ a.subject } onChange={ ( v ) => setA( { subject: v } ) } __nextHasNoMarginBottom />
			<TextareaControl label={ __( '本文（{フィールドキー} と {all_fields} が使えます）', 'wp-entry-form' ) } value={ a.body } onChange={ ( v ) => setA( { body: v } ) } __nextHasNoMarginBottom />

			<h3 style={ { marginTop: 24 } }>{ __( '自動返信メール', 'wp-entry-form' ) }</h3>
			<ToggleControl label={ __( '有効にする', 'wp-entry-form' ) } checked={ r.enabled } onChange={ ( v ) => setR( { enabled: v } ) } __nextHasNoMarginBottom />
			<SelectControl
				label={ __( '宛先となるメールフィールド', 'wp-entry-form' ) }
				value={ r.to_field }
				options={ [ { label: __( '— 選択 —', 'wp-entry-form' ), value: '' } ].concat( fieldKeys.map( ( k ) => ( { label: k, value: k } ) ) ) }
				onChange={ ( v ) => setR( { to_field: v } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl label={ __( '差出人名', 'wp-entry-form' ) } value={ r.from_name } onChange={ ( v ) => setR( { from_name: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '差出人メール', 'wp-entry-form' ) } value={ r.from_email } onChange={ ( v ) => setR( { from_email: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '件名', 'wp-entry-form' ) } value={ r.subject } onChange={ ( v ) => setR( { subject: v } ) } __nextHasNoMarginBottom />
			<TextareaControl label={ __( '本文', 'wp-entry-form' ) } value={ r.body } onChange={ ( v ) => setR( { body: v } ) } __nextHasNoMarginBottom />
		</Fragment>
	);
}

function SettingsSpam( { settings, set } ) {
	const spam = settings.spam;
	const rate = spam.rate_limit;
	const setSpam = ( p ) => set( { spam: { ...spam, ...p } } );
	const setRate = ( p ) => set( { spam: { ...spam, rate_limit: { ...rate, ...p } } } );
	return (
		<Fragment>
			<ToggleControl label={ __( 'ハニーポット（隠し項目によるボット対策）', 'wp-entry-form' ) } checked={ spam.honeypot } onChange={ ( v ) => setSpam( { honeypot: v } ) } __nextHasNoMarginBottom />
			<ToggleControl label={ __( '送信レート制限', 'wp-entry-form' ) } checked={ rate.enabled } onChange={ ( v ) => setRate( { enabled: v } ) } __nextHasNoMarginBottom />
			{ rate.enabled && (
				<Flex gap={ 2 }>
					<FlexBlock><TextControl type="number" label={ __( '最大回数', 'wp-entry-form' ) } value={ rate.max } onChange={ ( v ) => setRate( { max: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom /></FlexBlock>
					<FlexBlock><TextControl type="number" label={ __( '時間窓（秒）', 'wp-entry-form' ) } value={ rate.window } onChange={ ( v ) => setRate( { window: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom /></FlexBlock>
				</Flex>
			) }
		</Fragment>
	);
}

/* ------------------------------------------------------------------ */
/* ルート                                                              */
/* ------------------------------------------------------------------ */
function Builder() {
	const [ fields, setFields ] = useState( Array.isArray( data.fields ) ? data.fields : [] );
	const [ settings, setSettings ] = useState( defaultSettings( data.settings ) );
	let dragIndex = null;

	const updateField = ( i, next ) => setFields( ( prev ) => prev.map( ( f, idx ) => ( idx === i ? next : f ) ) );
	const removeField = ( i ) => setFields( ( prev ) => prev.filter( ( f, idx ) => idx !== i ) );
	const addField = ( type ) => setFields( ( prev ) => [ ...prev, newField( type ) ] );
	const moveField = ( i, dir ) => setFields( ( prev ) => {
		const j = i + dir;
		if ( j < 0 || j >= prev.length ) {
			return prev;
		}
		const next = prev.slice();
		const tmp = next[ i ];
		next[ i ] = next[ j ];
		next[ j ] = tmp;
		return next;
	} );

	const dragHandlers = {
		onDragStart: ( e, i ) => { dragIndex = i; e.dataTransfer.effectAllowed = 'move'; },
		onDragOver: ( e ) => { e.preventDefault(); },
		onDrop: ( e, i ) => {
			e.preventDefault();
			if ( dragIndex === null || dragIndex === i ) {
				return;
			}
			setFields( ( prev ) => {
				const next = prev.slice();
				const [ moved ] = next.splice( dragIndex, 1 );
				next.splice( i, 0, moved );
				return next;
			} );
			dragIndex = null;
		},
	};

	const setSettingsPartial = ( p ) => setSettings( ( prev ) => ( { ...prev, ...p } ) );
	const fieldKeys = fields.filter( ( f ) => typeMeta( f.type ).input && f.key ).map( ( f ) => f.key );

	return (
		<div className="wpef-builder">
			<input type="hidden" name="wpef_fields" value={ JSON.stringify( fields ) } />
			<input type="hidden" name="wpef_settings" value={ JSON.stringify( settings ) } />

			<TabPanel
				className="wpef-tabs"
				tabs={ [
					{ name: 'fields', title: __( 'フィールド', 'wp-entry-form' ) },
					{ name: 'basic', title: __( '基本設定', 'wp-entry-form' ) },
					{ name: 'mail', title: __( 'メール', 'wp-entry-form' ) },
					{ name: 'spam', title: __( 'スパム対策', 'wp-entry-form' ) },
				] }
			>
				{ ( tab ) => (
					<div style={ { paddingTop: 16 } }>
						{ tab.name === 'fields' && (
							<Fragment>
								{ fields.length === 0 && (
									<Notice status="info" isDismissible={ false }>{ __( '下のボタンからフィールドを追加してください。', 'wp-entry-form' ) }</Notice>
								) }
								{ fields.map( ( field, i ) => (
									<FieldCard
										key={ i }
										field={ field }
										index={ i }
										total={ fields.length }
										onChange={ updateField }
										onRemove={ removeField }
										onMove={ moveField }
										dragHandlers={ dragHandlers }
									/>
								) ) }
								<Card style={ { marginTop: 8 } }>
									<CardBody>
										<strong>{ __( 'フィールドを追加', 'wp-entry-form' ) }</strong>
										<Flex wrap gap={ 1 } justify="flex-start" style={ { marginTop: 8 } }>
											{ FIELD_TYPES.map( ( t ) => (
												<FlexItem key={ t.type }>
													<Button variant="secondary" size="compact" onClick={ () => addField( t.type ) }>
														+ { t.label }
													</Button>
												</FlexItem>
											) ) }
										</Flex>
									</CardBody>
								</Card>
							</Fragment>
						) }
						{ tab.name === 'basic' && <SettingsBasic settings={ settings } set={ setSettingsPartial } /> }
						{ tab.name === 'mail' && <SettingsMail settings={ settings } set={ setSettingsPartial } fieldKeys={ fieldKeys } /> }
						{ tab.name === 'spam' && <SettingsSpam settings={ settings } set={ setSettingsPartial } /> }
					</div>
				) }
			</TabPanel>
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const mount = document.getElementById( 'wpef-form-builder' );
	if ( ! mount ) {
		return;
	}
	mount.innerHTML = '';
	createRoot( mount ).render( <Builder /> );
} );
