/**
 * フォームビルダー（管理画面）の React UI。
 *
 * window.wpefBuilder（PHP の wp_localize_script）から初期のフィールド・設定・型一覧を読み、
 * フィールドの追加/編集/削除/並べ替えと設定タブ（基本/メール/スパム）を提供する。
 * 各フィールドカードは「左に設定・右にプレビュー」の2カラムで、左の操作を変えると
 * 右の実際の表示が即座に反映される（専門用語はできるだけ避ける）。スマホ幅では
 * 縦積みにし、入力はタップしやすいサイズにする（CSS: admin/css/wpef-admin.css）。
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

// プレースホルダ（入力欄のヒント）を持てる型。
const HINT_TYPES = [ 'text', 'textarea', 'email', 'tel', 'url', 'number', 'date' ];

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
	const f = { type, label: '' };
	if ( meta.input ) {
		f.key = '';
		f.required = false;
		f.placeholder = '';
		f.help = '';
		f.default = '';
		if ( meta.hasOptions ) {
			f.options = [
				{ label: __( '選択肢1', 'wp-entry-form' ), value: __( '選択肢1', 'wp-entry-form' ) },
				{ label: __( '選択肢2', 'wp-entry-form' ), value: __( '選択肢2', 'wp-entry-form' ) },
			];
		}
		if ( type === 'file' ) {
			f.file = { max_size_mb: 5, accept: [ 'pdf', 'jpg', 'jpeg', 'png' ] };
		}
		f.validation = {};
	}
	return f;
}

/* ------------------------------------------------------------------ */
/* 選択肢の編集（選択肢のテキストのみ。保存値はテキストと同一にする）  */
/* ------------------------------------------------------------------ */
function OptionsEditor( { options, onChange } ) {
	const list = options || [];
	const update = ( i, text ) => onChange( list.map( ( o, idx ) => ( idx === i ? { label: text, value: text } : o ) ) );
	return (
		<div className="wpef-options-editor">
			<p className="wpef-ctrl-label">{ __( '選択肢', 'wp-entry-form' ) }</p>
			{ list.map( ( opt, i ) => (
				<div key={ i } className="wpef-opt-row">
					<input
						type="text"
						className="wpef-opt-input"
						value={ opt.label || '' }
						placeholder={ __( '選択肢のテキスト', 'wp-entry-form' ) }
						onChange={ ( e ) => update( i, e.target.value ) }
					/>
					<Button isDestructive variant="tertiary" label={ __( '削除', 'wp-entry-form' ) } onClick={ () => onChange( list.filter( ( o, idx ) => idx !== i ) ) }>✕</Button>
				</div>
			) ) }
			<Button variant="secondary" size="small" onClick={ () => onChange( [ ...list, { label: '', value: '' } ] ) }>
				{ __( '＋ 選択肢を追加', 'wp-entry-form' ) }
			</Button>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* プレビューの入力欄（操作不可の見本）                                */
/* ------------------------------------------------------------------ */
function PreviewControl( { field } ) {
	const ph = field.placeholder || '';
	const opts = field.options || [];
	switch ( field.type ) {
		case 'textarea':
			return <textarea className="wpef-pv-input" rows={ 3 } placeholder={ ph } disabled />;
		case 'select':
			return (
				<select className="wpef-pv-input" disabled>
					<option>{ __( '選択してください', 'wp-entry-form' ) }</option>
					{ opts.map( ( o, i ) => <option key={ i }>{ o.label || __( '選択肢', 'wp-entry-form' ) }</option> ) }
				</select>
			);
		case 'radio':
			return (
				<div className="wpef-pv-options">
					{ opts.map( ( o, i ) => (
						<label key={ i } className="wpef-pv-option"><input type="radio" disabled /> { o.label || __( '選択肢', 'wp-entry-form' ) }</label>
					) ) }
				</div>
			);
		case 'checkbox':
			return (
				<div className="wpef-pv-options">
					{ opts.map( ( o, i ) => (
						<label key={ i } className="wpef-pv-option"><input type="checkbox" disabled /> { o.label || __( '選択肢', 'wp-entry-form' ) }</label>
					) ) }
				</div>
			);
		case 'file':
			return <input type="file" className="wpef-pv-input" disabled />;
		case 'number':
			return <input type="number" className="wpef-pv-input" placeholder={ ph } disabled />;
		default: {
			const t = [ 'email', 'tel', 'url', 'date' ].includes( field.type ) ? field.type : 'text';
			return <input type={ t } className="wpef-pv-input" placeholder={ ph } disabled />;
		}
	}
}

/* 右カラム: フィールドの「実際の表示」プレビュー（読み取り専用）。 */
function FieldPreview( { field } ) {
	const required = field.required ? <span className="wpef-req" title={ __( '必須', 'wp-entry-form' ) }>*</span> : null;

	if ( field.type === 'heading' ) {
		return <h3 className="wpef-pv-heading">{ field.label || __( '（見出し）', 'wp-entry-form' ) }</h3>;
	}
	if ( field.type === 'paragraph' ) {
		return <p className="wpef-pv-para">{ field.label || __( '（説明文）', 'wp-entry-form' ) }</p>;
	}
	if ( field.type === 'consent' ) {
		return (
			<label className="wpef-pv-consent">
				<input type="checkbox" disabled /> <span>{ field.label || __( '（同意の文）', 'wp-entry-form' ) }</span>{ required }
			</label>
		);
	}
	return (
		<div className="wpef-pv-field">
			<span className="wpef-pv-label">{ field.label || __( '（項目名）', 'wp-entry-form' ) }{ required }</span>
			<PreviewControl field={ field } />
			{ field.help && <span className="wpef-pv-help">{ field.help }</span> }
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* 左カラム: フィールドの設定                                          */
/* ------------------------------------------------------------------ */
function FieldSettings( { field, onChange } ) {
	const meta = typeMeta( field.type );
	const patch = ( p ) => onChange( { ...field, ...p } );
	const patchValidation = ( p ) => onChange( { ...field, validation: { ...( field.validation || {} ), ...p } } );

	// 見出し・説明文（表示専用）。
	if ( meta.display ) {
		return (
			<TextControl
				label={ field.type === 'heading' ? __( '見出しのテキスト', 'wp-entry-form' ) : __( '説明文のテキスト', 'wp-entry-form' ) }
				value={ field.label || '' }
				onChange={ ( v ) => patch( { label: v } ) }
				__nextHasNoMarginBottom
			/>
		);
	}

	const advanced = (
		<details className="wpef-advanced">
			<summary>{ __( '詳細設定', 'wp-entry-form' ) }</summary>
			<TextControl
				label={ __( '保存名（英数字・空欄なら自動）', 'wp-entry-form' ) }
				value={ field.key || '' }
				onChange={ ( v ) => patch( { key: v } ) }
				help={ __( 'メールの差し込みや CSV の列名に使われます。通常は空欄のままで構いません。', 'wp-entry-form' ) }
				__nextHasNoMarginBottom
			/>
		</details>
	);

	// 同意チェック。
	if ( field.type === 'consent' ) {
		return (
			<Fragment>
				<TextControl
					label={ __( '同意の文', 'wp-entry-form' ) }
					value={ field.label || '' }
					onChange={ ( v ) => patch( { label: v } ) }
					help={ __( '例：プライバシーポリシーに同意します', 'wp-entry-form' ) }
					__nextHasNoMarginBottom
				/>
				<ToggleControl label={ __( '同意を必須にする', 'wp-entry-form' ) } checked={ !! field.required } onChange={ ( v ) => patch( { required: v } ) } __nextHasNoMarginBottom />
				{ advanced }
			</Fragment>
		);
	}

	// 通常の入力フィールド。
	return (
		<Fragment>
			<TextControl
				label={ __( '項目名', 'wp-entry-form' ) }
				value={ field.label || '' }
				onChange={ ( v ) => patch( { label: v } ) }
				placeholder={ __( '例：お名前', 'wp-entry-form' ) }
				__nextHasNoMarginBottom
			/>

			<ToggleControl label={ __( '入力を必須にする', 'wp-entry-form' ) } checked={ !! field.required } onChange={ ( v ) => patch( { required: v } ) } __nextHasNoMarginBottom />

			{ meta.hasOptions && (
				<OptionsEditor options={ field.options || [] } onChange={ ( opts ) => patch( { options: opts } ) } />
			) }

			{ HINT_TYPES.includes( field.type ) && (
				<TextControl
					label={ __( '入力欄のヒント（うすく表示される入力例）', 'wp-entry-form' ) }
					value={ field.placeholder || '' }
					onChange={ ( v ) => patch( { placeholder: v } ) }
					__nextHasNoMarginBottom
				/>
			) }

			{ field.type === 'number' && (
				<Flex className="wpef-row" gap={ 4 }>
					<FlexBlock>
						<TextControl type="number" label={ __( '最小値', 'wp-entry-form' ) } value={ field.validation?.min ?? '' } onChange={ ( v ) => patchValidation( { min: v } ) } __nextHasNoMarginBottom />
					</FlexBlock>
					<FlexBlock>
						<TextControl type="number" label={ __( '最大値', 'wp-entry-form' ) } value={ field.validation?.max ?? '' } onChange={ ( v ) => patchValidation( { max: v } ) } __nextHasNoMarginBottom />
					</FlexBlock>
				</Flex>
			) }

			{ ( field.type === 'text' || field.type === 'textarea' ) && (
				<TextControl type="number" label={ __( '最大文字数（任意）', 'wp-entry-form' ) } value={ field.validation?.max_length ?? '' } onChange={ ( v ) => patchValidation( { max_length: v } ) } __nextHasNoMarginBottom />
			) }

			{ field.type === 'file' && (
				<Flex className="wpef-row" gap={ 4 }>
					<FlexBlock>
						<TextControl
							label={ __( '受け付けるファイル形式（カンマ区切り）', 'wp-entry-form' ) }
							value={ ( field.file?.accept || [] ).join( ', ' ) }
							onChange={ ( v ) => patch( { file: { ...( field.file || {} ), accept: v.split( ',' ).map( ( s ) => s.trim().replace( /^\./, '' ) ).filter( Boolean ) } } ) }
							help={ __( '例：pdf, jpg, png', 'wp-entry-form' ) }
							__nextHasNoMarginBottom
						/>
					</FlexBlock>
					<FlexItem>
						<TextControl type="number" label={ __( '最大サイズ(MB)', 'wp-entry-form' ) } value={ field.file?.max_size_mb ?? 5 } onChange={ ( v ) => patch( { file: { ...( field.file || {} ), max_size_mb: v } } ) } __nextHasNoMarginBottom />
					</FlexItem>
				</Flex>
			) }

			<TextControl
				label={ __( '補足説明（任意・項目の下に表示）', 'wp-entry-form' ) }
				value={ field.help || '' }
				onChange={ ( v ) => patch( { help: v } ) }
				__nextHasNoMarginBottom
			/>

			{ advanced }
		</Fragment>
	);
}

/* ------------------------------------------------------------------ */
/* フィールド1件のカード（左=設定 / 右=プレビュー）                    */
/* ------------------------------------------------------------------ */
function FieldCard( { field, index, total, onChange, onRemove, onMove, dragHandlers } ) {
	const meta = typeMeta( field.type );
	return (
		<Card
			className="wpef-field-card"
			draggable
			onDragStart={ ( e ) => dragHandlers.onDragStart( e, index ) }
			onDragOver={ dragHandlers.onDragOver }
			onDrop={ ( e ) => dragHandlers.onDrop( e, index ) }
		>
			<CardHeader className="wpef-field-head">
				<Flex>
					<FlexBlock>
						<span className="wpef-drag-handle" title={ __( 'ドラッグで並べ替え', 'wp-entry-form' ) }>⠿</span>
						<strong>{ meta.label || field.type }</strong>
					</FlexBlock>
					<FlexItem>
						<Button size="small" variant="tertiary" disabled={ index === 0 } label={ __( '上へ', 'wp-entry-form' ) } onClick={ () => onMove( index, -1 ) }>↑</Button>
						<Button size="small" variant="tertiary" disabled={ index === total - 1 } label={ __( '下へ', 'wp-entry-form' ) } onClick={ () => onMove( index, 1 ) }>↓</Button>
						<Button size="small" isDestructive variant="tertiary" onClick={ () => onRemove( index ) }>{ __( '削除', 'wp-entry-form' ) }</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				<div className="wpef-cols">
					<div className="wpef-col-settings">
						<FieldSettings field={ field } onChange={ ( next ) => onChange( index, next ) } />
					</div>
					<div className="wpef-col-preview">
						<span className="wpef-pv-caption">{ __( 'プレビュー', 'wp-entry-form' ) }</span>
						<div className="wpef-pv-body">
							<FieldPreview field={ field } />
						</div>
					</div>
				</div>
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
		<div className="wpef-settings-panel">
			<ToggleControl label={ __( '送信前に確認画面を表示する', 'wp-entry-form' ) } checked={ settings.confirmation_screen } onChange={ ( v ) => set( { confirmation_screen: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '送信ボタンの文言', 'wp-entry-form' ) } value={ m.submit_button } onChange={ ( v ) => setMsg( { submit_button: v } ) } __nextHasNoMarginBottom />
			{ settings.confirmation_screen && (
				<Fragment>
					<TextControl label={ __( '確認ボタンの文言', 'wp-entry-form' ) } value={ m.confirm_button } onChange={ ( v ) => setMsg( { confirm_button: v } ) } __nextHasNoMarginBottom />
					<TextControl label={ __( '戻るボタンの文言', 'wp-entry-form' ) } value={ m.back_button } onChange={ ( v ) => setMsg( { back_button: v } ) } __nextHasNoMarginBottom />
				</Fragment>
			) }
			<TextareaControl label={ __( '送信完了後に表示するメッセージ', 'wp-entry-form' ) } value={ m.success } onChange={ ( v ) => setMsg( { success: v } ) } __nextHasNoMarginBottom />
			<TextControl label={ __( '送信完了後に移動する URL（任意・空なら上のメッセージを表示）', 'wp-entry-form' ) } value={ settings.redirect_url } onChange={ ( v ) => set( { redirect_url: v } ) } __nextHasNoMarginBottom />
		</div>
	);
}

function SettingsMail( { settings, set, fieldKeys } ) {
	const a = settings.admin_notification;
	const r = settings.autoresponder;
	const setA = ( p ) => set( { admin_notification: { ...a, ...p } } );
	const setR = ( p ) => set( { autoresponder: { ...r, ...p } } );
	return (
		<div className="wpef-settings-panel">
			<h3>{ __( '管理者へのお知らせメール', 'wp-entry-form' ) }</h3>
			<ToggleControl label={ __( '応募があったら管理者に通知する', 'wp-entry-form' ) } checked={ a.enabled } onChange={ ( v ) => setA( { enabled: v } ) } __nextHasNoMarginBottom />
			{ a.enabled && (
				<Fragment>
					<TextControl label={ __( '通知先メールアドレス（カンマ区切り・空ならサイト管理者）', 'wp-entry-form' ) } value={ a.to } onChange={ ( v ) => setA( { to: v } ) } __nextHasNoMarginBottom />
					<TextControl label={ __( 'メールの件名', 'wp-entry-form' ) } value={ a.subject } onChange={ ( v ) => setA( { subject: v } ) } __nextHasNoMarginBottom />
					<TextareaControl label={ __( 'メール本文', 'wp-entry-form' ) } value={ a.body } onChange={ ( v ) => setA( { body: v } ) } help={ __( '{all_fields} で全項目、{保存名} で個別の入力値を差し込めます。', 'wp-entry-form' ) } __nextHasNoMarginBottom />
				</Fragment>
			) }

			<h3 className="wpef-section-gap">{ __( '応募者への自動返信メール', 'wp-entry-form' ) }</h3>
			<ToggleControl label={ __( '応募者に自動で返信する', 'wp-entry-form' ) } checked={ r.enabled } onChange={ ( v ) => setR( { enabled: v } ) } __nextHasNoMarginBottom />
			{ r.enabled && (
				<Fragment>
					<SelectControl
						label={ __( '返信先にするメール項目', 'wp-entry-form' ) }
						value={ r.to_field }
						options={ [ { label: __( '— 選んでください —', 'wp-entry-form' ), value: '' } ].concat( fieldKeys.map( ( f ) => ( { label: f.label, value: f.key } ) ) ) }
						onChange={ ( v ) => setR( { to_field: v } ) }
						help={ __( 'メール型の項目を作っておくと選べます。', 'wp-entry-form' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl label={ __( '差出人の名前', 'wp-entry-form' ) } value={ r.from_name } onChange={ ( v ) => setR( { from_name: v } ) } __nextHasNoMarginBottom />
					<TextControl label={ __( '差出人のメールアドレス', 'wp-entry-form' ) } value={ r.from_email } onChange={ ( v ) => setR( { from_email: v } ) } __nextHasNoMarginBottom />
					<TextControl label={ __( 'メールの件名', 'wp-entry-form' ) } value={ r.subject } onChange={ ( v ) => setR( { subject: v } ) } __nextHasNoMarginBottom />
					<TextareaControl label={ __( 'メール本文', 'wp-entry-form' ) } value={ r.body } onChange={ ( v ) => setR( { body: v } ) } __nextHasNoMarginBottom />
				</Fragment>
			) }
		</div>
	);
}

function SettingsSpam( { settings, set } ) {
	const spam = settings.spam;
	const rate = spam.rate_limit;
	const setSpam = ( p ) => set( { spam: { ...spam, ...p } } );
	const setRate = ( p ) => set( { spam: { ...spam, rate_limit: { ...rate, ...p } } } );
	return (
		<div className="wpef-settings-panel">
			<ToggleControl label={ __( 'いたずら送信を防ぐ（隠し項目によるボット対策）', 'wp-entry-form' ) } checked={ spam.honeypot } onChange={ ( v ) => setSpam( { honeypot: v } ) } __nextHasNoMarginBottom />
			<ToggleControl label={ __( '短時間の連続送信を制限する', 'wp-entry-form' ) } checked={ rate.enabled } onChange={ ( v ) => setRate( { enabled: v } ) } __nextHasNoMarginBottom />
			{ rate.enabled && (
				<Flex className="wpef-row" gap={ 4 }>
					<FlexBlock><TextControl type="number" label={ __( '許可する回数', 'wp-entry-form' ) } value={ rate.max } onChange={ ( v ) => setRate( { max: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom /></FlexBlock>
					<FlexBlock><TextControl type="number" label={ __( '対象の時間（秒）', 'wp-entry-form' ) } value={ rate.window } onChange={ ( v ) => setRate( { window: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom /></FlexBlock>
				</Flex>
			) }
		</div>
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
	// 自動返信の宛先候補（キーを持つ入力フィールド）。
	const fieldKeys = fields
		.filter( ( f ) => typeMeta( f.type ).input && f.key )
		.map( ( f ) => ( { key: f.key, label: f.label ? `${ f.label }（${ f.key }）` : f.key } ) );

	return (
		<div className="wpef-builder">
			<input type="hidden" name="wpef_fields" value={ JSON.stringify( fields ) } />
			<input type="hidden" name="wpef_settings" value={ JSON.stringify( settings ) } />

			<TabPanel
				className="wpef-tabs"
				tabs={ [
					{ name: 'fields', title: __( '入力項目', 'wp-entry-form' ) },
					{ name: 'basic', title: __( '基本設定', 'wp-entry-form' ) },
					{ name: 'mail', title: __( 'メール', 'wp-entry-form' ) },
					{ name: 'spam', title: __( 'スパム対策', 'wp-entry-form' ) },
				] }
			>
				{ ( tab ) => (
					<div className="wpef-tab-body">
						{ tab.name === 'fields' && (
							<Fragment>
								{ fields.length === 0 && (
									<Notice status="info" isDismissible={ false }>{ __( '下のボタンから入力項目を追加してください。', 'wp-entry-form' ) }</Notice>
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
								<Card className="wpef-add-card">
									<CardBody>
										<strong>{ __( '入力項目を追加', 'wp-entry-form' ) }</strong>
										<div className="wpef-add-buttons">
											{ FIELD_TYPES.map( ( t ) => (
												<Button key={ t.type } variant="secondary" size="small" onClick={ () => addField( t.type ) }>
													＋ { t.label }
												</Button>
											) ) }
										</div>
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
