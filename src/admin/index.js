/**
 * フォームビルダー（管理画面）の React UI。
 *
 * 編集はプレビュー1枚のキャンバスで行う：実際の表示を見ながら、
 * - ＋ボタンで入力項目を追加
 * - ラベルをクリックして直接編集
 * - ⚙ トグルで各項目の詳細設定を開閉
 * - ⠿ ドラッグで並べ替え、右端ドラッグで幅（cols 1〜12）を変更
 * フォーム全体の設定（基本/メール/スパム）はタブで切り替える。
 * 状態は隠し input（name="wpef_fields" / "wpef_settings"）に JSON で書き出し、
 * 周囲の PHP フォームの標準 POST で保存される（ビルド: @wordpress/scripts）。
 */
import { createRoot, useState, useRef, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FaGear, FaGripVertical, FaChevronLeft, FaChevronRight, FaTrashCan, FaPlus } from 'react-icons/fa6';
import {
	Button,
	Modal,
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

// グリッドは12カラム。横幅はフィールドの cols（1〜12）で表す。
const GRID_COLS = 12;

// よく使う幅のプリセット。
const WIDTH_PRESETS = [
	{ cols: 12, label: __( '全幅', 'wp-entry-form' ) },
	{ cols: 8, label: '2/3' },
	{ cols: 6, label: '1/2' },
	{ cols: 4, label: '1/3' },
	{ cols: 3, label: '1/4' },
];

// 旧来の名前付き幅 → cols（後方互換）。
const LEGACY_WIDTH_COLS = { full: 12, two_thirds: 8, half: 6, third: 4, quarter: 3 };

function fieldCols( f ) {
	if ( typeof f.cols === 'number' && f.cols >= 1 && f.cols <= GRID_COLS ) {
		return Math.round( f.cols );
	}
	if ( f.width && LEGACY_WIDTH_COLS[ f.width ] ) {
		return LEGACY_WIDTH_COLS[ f.width ];
	}
	return GRID_COLS;
}

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
	const f = { type, label: '', cols: GRID_COLS };
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
/* 選択肢の編集                                                        */
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
					<Button isDestructive variant="tertiary" label={ __( '削除', 'wp-entry-form' ) } onClick={ () => onChange( list.filter( ( o, idx ) => idx !== i ) ) }><FaTrashCan aria-hidden="true" /></Button>
				</div>
			) ) }
			<Button variant="secondary" size="small" icon={ <FaPlus aria-hidden="true" /> } iconSize={ 14 } onClick={ () => onChange( [ ...list, { label: '', value: '' } ] ) }>
				{ __( '選択肢を追加', 'wp-entry-form' ) }
			</Button>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* 横幅ピッカー（比率バー）                                            */
/* ------------------------------------------------------------------ */
function WidthControl( { field, patch } ) {
	const current = fieldCols( field );
	return (
		<div className="wpef-width-control">
			<p className="wpef-ctrl-label">{ __( '横幅', 'wp-entry-form' ) } <span className="wpef-cols-badge">{ current }/{ GRID_COLS }</span></p>
			<div className="wpef-width-picker" role="group" aria-label={ __( '横幅', 'wp-entry-form' ) }>
				{ WIDTH_PRESETS.map( ( w ) => (
					<button
						type="button"
						key={ w.cols }
						className={ 'wpef-wbtn' + ( current === w.cols ? ' is-active' : '' ) }
						aria-pressed={ current === w.cols }
						title={ w.label }
						onClick={ () => patch( { cols: w.cols } ) }
					>
						<span className="wpef-wbar"><span className="wpef-wfill" style={ { width: ( w.cols / GRID_COLS ) * 100 + '%' } } /></span>
						<span className="wpef-wlabel">{ w.label }</span>
					</button>
				) ) }
			</div>
			<p className="wpef-width-hint">{ __( 'プレビューの右端をドラッグして細かく調整もできます。スマホでは自動で全幅です。', 'wp-entry-form' ) }</p>
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

/* クリックで直接編集できるラベル。 */
function InlineLabel( { field, patch } ) {
	const t = field.type;
	if ( t === 'heading' ) {
		return <input type="text" className="wpef-inline-heading" value={ field.label || '' } placeholder={ __( '見出しを入力', 'wp-entry-form' ) } onChange={ ( e ) => patch( { label: e.target.value } ) } />;
	}
	if ( t === 'paragraph' ) {
		return <input type="text" className="wpef-inline-para" value={ field.label || '' } placeholder={ __( '説明文を入力', 'wp-entry-form' ) } onChange={ ( e ) => patch( { label: e.target.value } ) } />;
	}
	const ph = t === 'consent' ? __( '同意の文を入力', 'wp-entry-form' ) : __( '項目名を入力（例：お名前）', 'wp-entry-form' );
	return <input type="text" className="wpef-inline-label" value={ field.label || '' } placeholder={ ph } onChange={ ( e ) => patch( { label: e.target.value } ) } />;
}

/* ------------------------------------------------------------------ */
/* 詳細設定（ラベル以外）                                              */
/* ------------------------------------------------------------------ */
function FieldDetails( { field, onChange } ) {
	const meta = typeMeta( field.type );
	const patch = ( p ) => onChange( { ...field, ...p } );
	const patchValidation = ( p ) => onChange( { ...field, validation: { ...( field.validation || {} ), ...p } } );

	const advanced = (
		<details className="wpef-advanced">
			<summary>{ __( '保存名（上級者向け）', 'wp-entry-form' ) }</summary>
			<TextControl
				label={ __( '保存名（英数字・空欄なら自動）', 'wp-entry-form' ) }
				value={ field.key || '' }
				onChange={ ( v ) => patch( { key: v } ) }
				help={ __( 'メールの差し込みや CSV の列名に使われます。通常は空欄のままで構いません。', 'wp-entry-form' ) }
				__nextHasNoMarginBottom
			/>
		</details>
	);

	if ( meta.display ) {
		return <WidthControl field={ field } patch={ patch } />;
	}

	if ( field.type === 'consent' ) {
		return (
			<Fragment>
				<ToggleControl label={ __( '同意を必須にする', 'wp-entry-form' ) } checked={ !! field.required } onChange={ ( v ) => patch( { required: v } ) } __nextHasNoMarginBottom />
				<WidthControl field={ field } patch={ patch } />
				{ advanced }
			</Fragment>
		);
	}

	return (
		<Fragment>
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

			<WidthControl field={ field } patch={ patch } />

			{ advanced }
		</Fragment>
	);
}

/* ------------------------------------------------------------------ */
/* キャンバス上の編集可能フィールド                                    */
/* ------------------------------------------------------------------ */
function EditableField( { field, index, total, onChange, onRemove, onMove, onResizeStart, dragHandlers } ) {
	const meta = typeMeta( field.type );
	const cols = fieldCols( field );
	const [ open, setOpen ] = useState( false );
	const patch = ( p ) => onChange( index, { ...field, ...p } );
	const requiredBadge = field.required ? <span className="wpef-req">[{ __( '必須', 'wp-entry-form' ) }]</span> : null;

	let body;
	if ( meta.display ) {
		body = <InlineLabel field={ field } patch={ patch } />;
	} else if ( field.type === 'consent' ) {
		body = (
			<label className="wpef-pv-consent">
				<input type="checkbox" disabled />
				<InlineLabel field={ field } patch={ patch } />
				{ requiredBadge }
			</label>
		);
	} else {
		body = (
			<Fragment>
				<div className="wpef-pv-label-row">
					<InlineLabel field={ field } patch={ patch } />
					{ requiredBadge }
				</div>
				<PreviewControl field={ field } />
				{ field.help && <span className="wpef-pv-help">{ field.help }</span> }
			</Fragment>
		);
	}

	return (
		<div
			className={ `wpef-ef wpef-col-${ cols }${ open ? ' is-open' : '' }` }
			onDragOver={ dragHandlers.onDragOver }
			onDrop={ ( e ) => dragHandlers.onDrop( e, index ) }
		>
			<div className="wpef-ef-toolbar">
				<span className="wpef-drag-handle" draggable onDragStart={ ( e ) => dragHandlers.onDragStart( e, index ) } title={ __( 'ドラッグで移動', 'wp-entry-form' ) }><FaGripVertical aria-hidden="true" draggable={ false } /></span>
				<span className="wpef-ef-type">{ meta.label || field.type }</span>
				<span className="wpef-ef-spacer" />
				<Button size="small" variant="tertiary" disabled={ index === 0 } label={ __( '前へ', 'wp-entry-form' ) } onClick={ () => onMove( index, -1 ) }><FaChevronLeft aria-hidden="true" /></Button>
				<Button size="small" variant="tertiary" disabled={ index === total - 1 } label={ __( '次へ', 'wp-entry-form' ) } onClick={ () => onMove( index, 1 ) }><FaChevronRight aria-hidden="true" /></Button>
				<Button size="small" variant={ open ? 'primary' : 'tertiary' } aria-expanded={ open } label={ __( '詳細設定', 'wp-entry-form' ) } onClick={ () => setOpen( ! open ) }><FaGear aria-hidden="true" /></Button>
				<Button size="small" isDestructive variant="tertiary" label={ __( '削除', 'wp-entry-form' ) } onClick={ () => onRemove( index ) }><FaTrashCan aria-hidden="true" /></Button>
			</div>

			<div className="wpef-ef-body">{ body }</div>

			{ open && (
				<Modal
					title={ ( meta.label || field.type ) + __( ' の詳細設定', 'wp-entry-form' ) }
					onRequestClose={ () => setOpen( false ) }
					className="wpef-field-modal"
				>
					<div className="wpef-modal-body">
						<FieldDetails field={ field } onChange={ ( next ) => onChange( index, next ) } />
						<div className="wpef-modal-actions">
							<Button variant="primary" onClick={ () => setOpen( false ) }>{ __( '閉じる', 'wp-entry-form' ) }</Button>
						</div>
					</div>
				</Modal>
			) }

			<span className="wpef-resize-handle" title={ __( 'ドラッグで幅を変更', 'wp-entry-form' ) } onPointerDown={ ( e ) => onResizeStart( e, index, cols ) } />
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* キャンバス（プレビュー＝編集面）                                    */
/* ------------------------------------------------------------------ */
function BuilderCanvas( { fields, settings, onChange, onRemove, onMove, onResize, onAdd, dragHandlers, gridRef } ) {
	const submitLabel = settings.messages.submit_button || __( '送信する', 'wp-entry-form' );

	const onResizeStart = ( e, index, startCols ) => {
		e.preventDefault();
		e.stopPropagation();
		const grid = gridRef.current;
		if ( ! grid ) {
			return;
		}
		const colW = grid.getBoundingClientRect().width / GRID_COLS;
		const startX = e.clientX;
		const move = ( ev ) => {
			const delta = ev.clientX - startX;
			let next = startCols + Math.round( delta / colW );
			next = Math.max( 1, Math.min( GRID_COLS, next ) );
			onResize( index, next );
		};
		const up = () => {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			document.body.classList.remove( 'wpef-resizing' );
		};
		document.body.classList.add( 'wpef-resizing' );
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	};

	return (
		<div className="wpef-canvas">
			<p className="wpef-canvas-note">{ __( 'ラベルをクリックで直接編集、⚙ で詳細設定。⠿ をドラッグで並べ替え、右端ドラッグで幅を変更できます。', 'wp-entry-form' ) }</p>

			<div className="wpef-fullpreview-form" ref={ gridRef }>
				{ fields.length === 0 && (
					<div className="wpef-canvas-empty">{ __( 'まだ項目がありません。下のボタンから追加してください。', 'wp-entry-form' ) }</div>
				) }
				{ fields.map( ( field, i ) => (
					<EditableField
						key={ i }
						field={ field }
						index={ i }
						total={ fields.length }
						onChange={ onChange }
						onRemove={ onRemove }
						onMove={ onMove }
						onResizeStart={ onResizeStart }
						dragHandlers={ dragHandlers }
					/>
				) ) }
				<div className="wpef-prev-actions">
					<button type="button" className="wpef-prev-submit" disabled>{ submitLabel }</button>
				</div>
			</div>

			<div className="wpef-add-card">
				<strong>{ __( '入力項目を追加', 'wp-entry-form' ) }</strong>
				<div className="wpef-add-buttons">
					{ FIELD_TYPES.map( ( t ) => (
						<Button key={ t.type } variant="secondary" size="small" icon={ <FaPlus aria-hidden="true" /> } iconSize={ 14 } onClick={ () => onAdd( t.type ) }>
							{ t.label }
						</Button>
					) ) }
				</div>
			</div>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* フォーム全体の設定タブ                                              */
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
	// 読み込み時に cols を正規化（旧来の名前付き width からの移行を含む）。
	const initialFields = ( Array.isArray( data.fields ) ? data.fields : [] ).map( ( f ) => ( { ...f, cols: fieldCols( f ) } ) );
	const [ fields, setFields ] = useState( initialFields );
	const [ settings, setSettings ] = useState( defaultSettings( data.settings ) );
	const gridRef = useRef( null );
	let dragIndex = null;

	const updateField = ( i, next ) => setFields( ( prev ) => prev.map( ( f, idx ) => ( idx === i ? next : f ) ) );
	const removeField = ( i ) => setFields( ( prev ) => prev.filter( ( f, idx ) => idx !== i ) );
	const addField = ( type ) => setFields( ( prev ) => [ ...prev, newField( type ) ] );
	const resizeField = ( i, cols ) => setFields( ( prev ) => prev.map( ( f, idx ) => ( idx === i ? { ...f, cols: Math.max( 1, Math.min( GRID_COLS, cols ) ) } : f ) ) );
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
					{ name: 'form', title: __( 'フォーム', 'wp-entry-form' ) },
					{ name: 'basic', title: __( '基本設定', 'wp-entry-form' ) },
					{ name: 'mail', title: __( 'メール', 'wp-entry-form' ) },
					{ name: 'spam', title: __( 'スパム対策', 'wp-entry-form' ) },
				] }
			>
				{ ( tab ) => (
					<div className="wpef-tab-body">
						{ tab.name === 'form' && (
							<BuilderCanvas
								fields={ fields }
								settings={ settings }
								onChange={ updateField }
								onRemove={ removeField }
								onMove={ moveField }
								onResize={ resizeField }
								onAdd={ addField }
								dragHandlers={ dragHandlers }
								gridRef={ gridRef }
							/>
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
