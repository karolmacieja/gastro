/* global Vue, EHTT_CONFIG */
(function () {
	'use strict';

	const { createApp, ref, reactive, computed, onMounted, watch } = Vue;

	const CFG = window.EHTT_CONFIG || {};

	/**
	 * Prosty klient REST do wp-json/ehtt/v1/*
	 */
	async function api( path, { method = 'GET', params = null, body = null } = {} ) {
		let url = CFG.restUrl + path;
		if ( params ) {
			const qs = new URLSearchParams( params ).toString();
			url += ( url.includes( '?' ) ? '&' : '?' ) + qs;
		}
		const opts = {
			method,
			headers: {
				'X-WP-Nonce': CFG.nonce,
			},
		};
		if ( body ) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		const res = await fetch( url, opts );
		const data = await res.json().catch( () => null );
		if ( ! res.ok ) {
			const message = ( data && data.message ) ? data.message : 'Błąd komunikacji z serwerem.';
			throw new Error( message );
		}
		return data;
	}

	function pad( n ) {
		return String( n ).padStart( 2, '0' );
	}

	function isoDate( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	function money( amount, currency ) {
		const n = Number( amount || 0 );
		return n.toLocaleString( 'pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' ' + ( currency || 'zł' );
	}

	/**
	 * Zaokrągla znacznik czasu "HH:MM" do najbliższej wielokrotności stepMinutes
	 * (domyślnie 15), np. 19:12 -> 19:15, 7:38 -> 7:30, 7:07 -> 7:00.
	 * Wywoływane przy każdej zmianie pola godziny, aby użytkownik NIGDY nie mógł
	 * zostawić w formularzu wartości spoza siatki 15-minutowej — niezależnie od
	 * tego, czy skorzystał ze strzałek, czy wpisał godzinę ręcznie z klawiatury.
	 */
	function roundTimeToStep( value, stepMinutes ) {
		stepMinutes = stepMinutes || 15;
		if ( ! value ) return value;
		const parts = value.split( ':' );
		const h = parseInt( parts[ 0 ], 10 );
		const m = parseInt( parts[ 1 ], 10 );
		if ( isNaN( h ) || isNaN( m ) ) return value;

		let total = h * 60 + m;
		let rounded = Math.round( total / stepMinutes ) * stepMinutes;
		rounded = ( ( rounded % ( 24 * 60 ) ) + 24 * 60 ) % ( 24 * 60 );

		return pad( Math.floor( rounded / 60 ) ) + ':' + pad( rounded % 60 );
	}

	/* ------------------------------------------------------------------ */
	/*  Komponent: Widok dnia                                             */
	/* ------------------------------------------------------------------ */
	const DayView = {
		props: [ 'userId', 'canManage', 'date' ],
		emits: [ 'saved' ],
		template: `
			<div class="ehtt-fade-in">
				<div class="ehtt-datebar">
					<button @click="shiftDay(-1)" aria-label="Poprzedni dzień"><i class="fa-solid fa-chevron-left"></i></button>
					<input type="date" v-model="localDate" @change="load" />
					<button @click="shiftDay(1)" aria-label="Następny dzień"><i class="fa-solid fa-chevron-right"></i></button>
				</div>

				<div v-if="loading" class="ehtt-loading">Wczytywanie…</div>
				<template v-else>
					<div class="ehtt-grid">
						<div class="ehtt-card">
							<h3>Godzina rozpoczęcia</h3>
							<div class="ehtt-time-display">
								<input type="time" step="900" v-model="startTime" @change="onTimeChange('start')" />
								<i class="fa-regular fa-clock"></i>
							</div>
							<p class="ehtt-muted" v-if="day.suggested_start">
								<span class="ehtt-tag">{{ sourceLabel }}: {{ day.suggested_start }}</span>
								<template v-if="!day.entry_exists">podpowiedź — zmień, jeśli zacząłeś/zaczęłaś o innej porze.</template>
							</p>
							<p class="ehtt-muted" v-else>Brak zmiany w grafiku — wpisz godzinę ręcznie.</p>
						</div>

						<div class="ehtt-card">
							<h3>Godzina zakończenia</h3>
							<div class="ehtt-time-display">
								<input type="time" step="900" v-model="endTime" @change="onTimeChange('end')" />
								<i class="fa-regular fa-clock"></i>
							</div>
							<p class="ehtt-muted" v-if="day.suggested_end">
								<span class="ehtt-tag">{{ sourceLabel }}: {{ day.suggested_end }}</span>
								<template v-if="!day.entry_exists">podpowiedź — zmień, jeśli skończyłeś/skończyłaś o innej porze.</template>
							</p>
							<p class="ehtt-muted">Czas zostanie zaokrąglony do 15 minut.</p>
						</div>
					</div>

					<div class="ehtt-stat-grid">
						<div class="ehtt-stat-card">
							<div class="ehtt-lbl">Przepracowany czas</div>
							<div class="ehtt-val">{{ computedHoursLabel }}</div>
							<div class="ehtt-sub">Stawka: {{ moneyFmt(day.hourly_rate) }}/h</div>
						</div>
						<div class="ehtt-stat-card">
							<div class="ehtt-lbl">Zarobek z godzin</div>
							<div class="ehtt-val">{{ moneyFmt(computedHoursEarnings) }}</div>
						</div>
						<div class="ehtt-stat-card">
							<div class="ehtt-lbl">Przelew (brutto)</div>
							<div class="ehtt-val">{{ moneyFmt(computedTransfer) }}</div>
							<div class="ehtt-sub">Godziny + karta + serwis + premia</div>
						</div>
						<div class="ehtt-stat-card ehtt-accent">
							<div class="ehtt-lbl">Łączny zarobek (netto)</div>
							<div class="ehtt-val">{{ moneyFmt(computedTotalEarnings) }}</div>
							<div class="ehtt-sub">Po rozliczeniu przelewu + gotówka</div>
						</div>
					</div>

					<div class="ehtt-card">
						<h3>Napiwki dnia</h3>
						<div class="ehtt-row"><span class="ehtt-row-name">Karta (brutto)</span><span>{{ moneyFmt(day.tips.card) }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name">Serwis (brutto)</span><span>{{ moneyFmt(day.tips.service) }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name">Gotówka <span class="ehtt-muted">· {{ cashSourceLabel }}</span></span><span>{{ moneyFmt(day.tips.cash) }}</span></div>
						<div class="ehtt-row" v-if="day.tips.cash_given > 0"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">— oddane do baru i kuchni</span><span>-{{ moneyFmt(day.tips.cash_given) }}</span></div>
						<div class="ehtt-row" v-if="day.tips.bonus > 0"><span class="ehtt-row-name">Premia</span><span>{{ moneyFmt(day.tips.bonus) }}</span></div>
						<p class="ehtt-muted" style="margin-top:8px;">{{ tipsSourceLabel }}. Karta, serwis i premia trafiają do przelewu; gotówka nie.</p>
						<template v-if="day.tips.settlement_gross > 0">
							<h3 style="margin-top:16px;">Rozliczenie karty i serwisu po przelewie (informacyjnie)</h3>
							<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Karta + serwis brutto</span><span>{{ moneyFmt(day.tips.settlement_gross) }}</span></div>
							<div class="ehtt-row" v-if="day.tips.tax > 0"><span class="ehtt-row-name ehtt-row-name-muted">— podatek</span><span>-{{ moneyFmt(day.tips.tax) }}</span></div>
							<div class="ehtt-row" v-if="day.tips.bar_cut > 0"><span class="ehtt-row-name ehtt-row-name-muted">— dla baru</span><span>-{{ moneyFmt(day.tips.bar_cut) }}</span></div>
							<div class="ehtt-row" v-if="day.tips.kitchen_cut > 0"><span class="ehtt-row-name ehtt-row-name-muted">— dla kuchni</span><span>-{{ moneyFmt(day.tips.kitchen_cut) }}</span></div>
							<div class="ehtt-total ehtt-strong"><span>Karta + serwis netto</span><span>{{ moneyFmt(day.tips.settlement_net) }}</span></div>
						</template>
					</div>

					<div v-if="day.is_waiter" class="ehtt-card">
						<h3>Moje wpisy (kelner) — informacyjnie</h3>
						<div class="ehtt-grid">
							<div>
								<label class="ehtt-field-label">Napiwki z gotówki (zł)</label>
								<input type="number" step="0.01" min="0" v-model="cashInput" placeholder="np. 80" />
							</div>
							<div>
								<label class="ehtt-field-label">Premia (zł)</label>
								<input type="number" step="0.01" min="0" v-model="bonusInput" placeholder="np. 50" />
							</div>
						</div>
						<p class="ehtt-muted" v-if="day.tips.cash_manual_ignored">W module Napiwków jest już gotówka z tego dnia — Twój wpis gotówki ({{ moneyFmt(day.tips.cash_manual) }}) nie jest liczony w podsumowaniu.</p>
						<p class="ehtt-muted">Z gotówki i premii nic nie oddajesz do baru ani kuchni. Premia wchodzi do przelewu, gotówka nie. Puste pole = brak wpisu.</p>
						<button class="ehtt-save ehtt-secondary ehtt-inline" @click="saveExtras" :disabled="saving">Zapisz gotówkę i premię</button>
					</div>

					<div class="ehtt-card">
						<h3>Notatka (opcjonalnie)</h3>
						<input type="text" v-model="note" placeholder="np. zastępstwo, wyjście służbowe..." />

						<button class="ehtt-save" @click="save" :disabled="saving">
							<i class="fa-solid fa-check"></i> {{ saving ? 'Zapisywanie…' : 'Zapisz dzień' }}
						</button>
						<button v-if="day.entry_exists" class="ehtt-save ehtt-secondary" @click="remove">
							<i class="fa-solid fa-trash"></i> Usuń wpis
						</button>

						<p v-if="errorMsg" class="ehtt-msg ehtt-err">{{ errorMsg }}</p>
						<p v-if="successMsg" class="ehtt-msg">{{ successMsg }}</p>
					</div>

					<div v-if="canManage" class="ehtt-card ehtt-role-accent">
						<h3>Zarządzanie (kierownik)</h3>
						<div class="ehtt-grid">
							<div>
								<label class="ehtt-field-label">Nadpisanie stawki na ten dzień (zł/h)</label>
								<input type="number" step="0.5" v-model="rateOverrideInput" placeholder="np. 35" />
								<button class="ehtt-save ehtt-secondary ehtt-inline" @click="saveRateOverride">Zapisz stawkę dnia</button>
							</div>
							<div>
								<label class="ehtt-field-label">Ręczne napiwki karta + serwis brutto (zastępują dane z Napiwków; puste = usuń)</label>
								<input type="number" step="0.5" v-model="manualTipsInput" placeholder="np. 120" />
								<button class="ehtt-save ehtt-secondary ehtt-inline" @click="saveManualTips">Zapisz napiwki</button>
							</div>
							<div>
								<label class="ehtt-field-label">Ręczne godziny podpowiedzi (zastępują Grafik; puste = usuń)</label>
								<div class="ehtt-grid">
									<input type="time" step="900" v-model="manualStartInput" />
									<input type="time" step="900" v-model="manualEndInput" />
								</div>
								<button class="ehtt-save ehtt-secondary ehtt-inline" @click="saveManualSchedule">Zapisz godziny</button>
							</div>
						</div>
					</div>
				</template>
			</div>
		`,
		setup( props, { emit } ) {
			const loading = ref( true );
			const saving = ref( false );
			const errorMsg = ref( '' );
			const successMsg = ref( '' );
			const localDate = ref( props.date );
			const startTime = ref( '' );
			const endTime = ref( '' );
			const note = ref( '' );
			const rateOverrideInput = ref( '' );
			const manualTipsInput = ref( '' );
			const manualStartInput = ref( '' );
			const cashInput = ref( '' );
			const bonusInput = ref( '' );
			const manualEndInput = ref( '' );
			const roundMinutes = ( window.EHTT_SETTINGS && window.EHTT_SETTINGS.round_minutes ) || 15;

			const day = reactive( {
				suggested_start: null,
				suggested_end: null,
				suggested_source: null,
				hours_decimal: 0,
				hours_formatted: '0 h',
				hourly_rate: 0,
				hours_earnings: 0,
				total_earnings: 0,
				entry_exists: false,
				is_waiter: false,
				tips: {
					card: 0, service: 0, cash: 0, cash_given: 0, cash_net: 0, bonus: 0, tax: 0,
					bar_cut: 0, kitchen_cut: 0, settlement_gross: 0, settlement_net: 0,
					transfer: 0, gross: 0, net: 0, source: null, cash_source: null,
					cash_manual: null, cash_manual_ignored: false,
				},
			} );

			const sourceLabel = computed( () => day.suggested_source === 'manual' ? 'Kierownik' : 'Grafik' );
			const tipsSourceLabel = computed( () => {
				if ( day.tips.source === 'napiwki' ) return 'Karta i serwis: moduł Napiwki';
				if ( day.tips.source === 'manual' ) return 'Karta i serwis: wpis kierownika (odliczenia z ustawień)';
				return 'Brak napiwków z karty i serwisu';
			} );
			const cashSourceLabel = computed( () => {
				if ( day.tips.cash_source === 'napiwki' ) return 'z modułu Napiwki';
				if ( day.tips.cash_source === 'kelner' ) return 'Twój wpis';
				return 'brak';
			} );

			async function load() {
				loading.value = true;
				errorMsg.value = '';
				try {
					const data = await api( '/day', { params: { date: localDate.value, user_id: props.userId } } );
					Object.assign( day, data );
					// Sugestia/zapisana godzina jest już zaokrąglona po stronie serwera,
					// ale zaokrąglamy defensywnie także tutaj.
					startTime.value = data.start_time ? roundTimeToStep( data.start_time, roundMinutes ) : '';
					endTime.value = data.end_time ? roundTimeToStep( data.end_time, roundMinutes ) : '';
					note.value = data.note || '';
					rateOverrideInput.value = '';
					manualTipsInput.value = '';
					cashInput.value = data.tips && data.tips.cash_manual !== null ? data.tips.cash_manual : '';
					bonusInput.value = data.tips && data.tips.bonus ? data.tips.bonus : '';
					manualStartInput.value = day.suggested_source === 'manual' ? ( data.suggested_start || '' ) : '';
					manualEndInput.value = day.suggested_source === 'manual' ? ( data.suggested_end || '' ) : '';
				} catch ( e ) {
					errorMsg.value = e.message;
				} finally {
					loading.value = false;
				}
			}

			// Przesuwa wybraną datę o +/-1 dzień (strzałki paska daty).
			function shiftDay( delta ) {
				const d = new Date( localDate.value + 'T00:00:00' );
				d.setDate( d.getDate() + delta );
				localDate.value = isoDate( d );
				load();
			}

			// Wymusza siatkę 15-minutową natychmiast po zmianie pola godziny
			// (obsługuje zarówno strzałki, jak i ręczne wpisanie z klawiatury).
			function onTimeChange( field ) {
				if ( field === 'start' ) {
					startTime.value = roundTimeToStep( startTime.value, roundMinutes );
				} else {
					endTime.value = roundTimeToStep( endTime.value, roundMinutes );
				}
			}

			// Przelicz godziny/zarobek "na żywo" po zmianie pól czasu (podgląd przed zapisem).
			const computedHoursLabel = computed( () => {
				if ( ! startTime.value || ! endTime.value ) {
					return day.hours_formatted;
				}
				const [ sh, sm ] = startTime.value.split( ':' ).map( Number );
				const [ eh, em ] = endTime.value.split( ':' ).map( Number );
				let mins = ( eh * 60 + em ) - ( sh * 60 + sm );
				if ( mins < 0 ) mins += 24 * 60;
				const rounded = Math.round( mins / roundMinutes ) * roundMinutes;
				const hrs = rounded / 60;
				const formatted = ( Math.round( hrs * 100 ) / 100 ).toString().replace( '.', ',' );
				return formatted + ' h';
			} );

			const computedHoursEarnings = computed( () => {
				const label = computedHoursLabel.value.replace( ' h', '' ).replace( ',', '.' );
				const hrs = parseFloat( label ) || 0;
				return Math.round( hrs * day.hourly_rate * 100 ) / 100;
			} );

			const computedTotalEarnings = computed( () => {
				return Math.round( ( computedHoursEarnings.value + ( day.tips.net || 0 ) ) * 100 ) / 100;
			} );

			const computedTransfer = computed( () => {
				return Math.round( ( computedHoursEarnings.value + ( day.tips.transfer || 0 ) ) * 100 ) / 100;
			} );

			async function saveExtras() {
				saving.value = true;
				errorMsg.value = '';
				successMsg.value = '';
				try {
					const data = await api( '/extras', {
						method: 'POST',
						body: {
							date: localDate.value,
							user_id: props.userId,
							cash: cashInput.value === '' ? null : cashInput.value,
							bonus: bonusInput.value === '' ? null : bonusInput.value,
						},
					} );
					Object.assign( day, data );
					successMsg.value = 'Zapisano gotówkę i premię.';
					emit( 'saved' );
				} catch ( e ) {
					errorMsg.value = e.message;
				} finally {
					saving.value = false;
				}
			}

			async function save() {
				saving.value = true;
				errorMsg.value = '';
				successMsg.value = '';
				try {
					const data = await api( '/day', {
						method: 'POST',
						body: {
							date: localDate.value,
							user_id: props.userId,
							start_time: roundTimeToStep( startTime.value, roundMinutes ),
							end_time: roundTimeToStep( endTime.value, roundMinutes ),
							note: note.value,
						},
					} );
					Object.assign( day, data );
					startTime.value = data.start_time || startTime.value;
					endTime.value = data.end_time || endTime.value;
					successMsg.value = 'Zapisano.';
					emit( 'saved' );
				} catch ( e ) {
					errorMsg.value = e.message;
				} finally {
					saving.value = false;
				}
			}

			async function remove() {
				if ( ! confirm( 'Usunąć wpis dla tego dnia?' ) ) return;
				try {
					await api( '/day', { method: 'DELETE', params: { date: localDate.value, user_id: props.userId } } );
					await load();
					emit( 'saved' );
				} catch ( e ) {
					errorMsg.value = e.message;
				}
			}

			async function saveRateOverride() {
				try {
					await api( '/rate-override', {
						method: 'POST',
						body: { date: localDate.value, user_id: props.userId, rate: rateOverrideInput.value || null },
					} );
					await load();
					successMsg.value = 'Stawka dnia zapisana.';
				} catch ( e ) {
					errorMsg.value = e.message;
				}
			}

			async function saveManualTips() {
				try {
					await api( '/manual-tips', {
						method: 'POST',
						body: { date: localDate.value, user_id: props.userId, amount: manualTipsInput.value === '' ? null : manualTipsInput.value },
					} );
					await load();
					successMsg.value = 'Napiwki zapisane.';
				} catch ( e ) {
					errorMsg.value = e.message;
				}
			}

			async function saveManualSchedule() {
				try {
					await api( '/manual-schedule', {
						method: 'POST',
						body: {
							date: localDate.value,
							user_id: props.userId,
							start_time: manualStartInput.value ? roundTimeToStep( manualStartInput.value, roundMinutes ) : '',
							end_time: manualEndInput.value ? roundTimeToStep( manualEndInput.value, roundMinutes ) : '',
						},
					} );
					await load();
					successMsg.value = 'Godziny podpowiedzi zapisane.';
				} catch ( e ) {
					errorMsg.value = e.message;
				}
			}

			function moneyFmt( v ) {
				return money( v, ( window.EHTT_SETTINGS && window.EHTT_SETTINGS.currency ) || 'zł' );
			}

			watch( () => props.date, ( v ) => { localDate.value = v; load(); } );
			watch( () => props.userId, load );

			onMounted( load );

			return {
				loading, saving, errorMsg, successMsg, localDate, startTime, endTime, note,
				day, computedHoursLabel, computedHoursEarnings, computedTotalEarnings,
				save, remove, moneyFmt, onTimeChange, shiftDay,
				rateOverrideInput, manualTipsInput, saveRateOverride, saveManualTips, load,
				manualStartInput, manualEndInput, saveManualSchedule, sourceLabel, tipsSourceLabel,
				cashInput, bonusInput, saveExtras, computedTransfer, cashSourceLabel,
			};
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Komponent: Widok kalendarza + podsumowanie miesiąca               */
	/* ------------------------------------------------------------------ */
	const CalendarView = {
		props: [ 'userId', 'currency', 'activeDate' ],
		emits: [ 'pick-day' ],
		template: `
			<div class="ehtt-fade-in">
				<div class="ehtt-month-nav">
					<button @click="prevMonth" aria-label="Poprzedni miesiąc"><i class="fa-solid fa-chevron-left"></i></button>
					<h3>{{ monthLabel }}</h3>
					<button @click="nextMonth" aria-label="Następny miesiąc"><i class="fa-solid fa-chevron-right"></i></button>
				</div>

				<div v-if="loading" class="ehtt-loading">Wczytywanie…</div>
				<template v-else>
					<div class="ehtt-calendar-card">
						<div class="ehtt-calendar-dow-row">
							<div v-for="d in dow" :key="d">{{ d }}</div>
						</div>
						<div class="ehtt-calendar-grid">
							<div v-for="n in leadingBlanks" :key="'b'+n" class="ehtt-day-cell ehtt-empty"></div>
							<div
								v-for="cell in cells"
								:key="cell.date"
								class="ehtt-day-cell"
								:class="cellClasses(cell)"
								@click="$emit('pick-day', cell.date)"
							>
								<div class="ehtt-num">{{ cell.day }}</div>
								<div class="ehtt-meta" v-if="cell.has_entry">{{ cell.hours_formatted }}<br />{{ moneyFmt(cell.hours_earnings) }}</div>
								<div class="ehtt-tip-chip" v-if="cell.tips.gross > 0"
									:title="'Karta ' + moneyFmt(cell.tips.card) + ' · Serwis ' + moneyFmt(cell.tips.service) + ' · Gotówka ' + moneyFmt(cell.tips.cash) + ' · Premia ' + moneyFmt(cell.tips.bonus)">+{{ moneyFmt(cell.tips.gross) }}</div>
							</div>
						</div>
					</div>

					<div class="ehtt-card" v-if="activeDays.length">
						<h3>Dzień po dniu</h3>
						<div class="ehtt-table-wrap">
							<table class="ehtt-table">
								<thead><tr><th>Dzień</th><th>Godz.</th><th>Zarobek</th><th>Karta</th><th>Serwis</th><th>Gotówka</th><th>Premia</th></tr></thead>
								<tbody>
									<tr v-for="c in activeDays" :key="c.date" @click="$emit('pick-day', c.date)">
										<td>{{ c.day }}</td>
										<td>{{ c.has_entry ? c.hours_formatted : '—' }}</td>
										<td>{{ moneyFmt(c.hours_earnings) }}</td>
										<td>{{ moneyFmt(c.tips.card) }}</td>
										<td>{{ moneyFmt(c.tips.service) }}</td>
										<td>{{ moneyFmt(c.tips.cash) }}</td>
										<td>{{ moneyFmt(c.tips.bonus) }}</td>
									</tr>
								</tbody>
								<tfoot><tr><th>Suma</th><th>{{ totals.hours_formatted }}</th><th>{{ moneyFmt(totals.hours_earnings) }}</th><th>{{ moneyFmt(totals.card) }}</th><th>{{ moneyFmt(totals.service) }}</th><th>{{ moneyFmt(totals.cash) }}</th><th>{{ moneyFmt(totals.bonus) }}</th></tr></tfoot>
							</table>
						</div>
					</div>

					<div class="ehtt-summary-box">
						<h3 style="font-size:16px;letter-spacing:normal;text-transform:none;margin-bottom:18px;">Podsumowanie miesiąca</h3>
						<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Suma godzin</span><span>{{ totals.hours_formatted }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Zarobek z godzin</span><span>{{ moneyFmt(totals.hours_earnings) }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Napiwki z karty (brutto)</span><span>{{ moneyFmt(totals.card) }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Napiwki z serwisu (brutto)</span><span>{{ moneyFmt(totals.service) }}</span></div>
						<div class="ehtt-row" v-if="totals.bonus > 0"><span class="ehtt-row-name ehtt-row-name-muted">Premia</span><span>{{ moneyFmt(totals.bonus) }}</span></div>
						<div class="ehtt-total ehtt-strong"><span>Przelew (brutto)</span><span>{{ moneyFmt(totals.transfer_amount) }}</span></div>

						<div class="ehtt-row" style="margin-top:14px;"><span class="ehtt-row-name ehtt-row-name-muted">Napiwki z gotówki</span><span>{{ moneyFmt(totals.cash) }}</span></div>
						<div class="ehtt-row" v-if="totals.cash_given > 0"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">— oddane do baru i kuchni</span><span>-{{ moneyFmt(totals.cash_given) }}</span></div>
						<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted">Gotówka do ręki (poza przelewem)</span><span>{{ moneyFmt(totals.cash_amount) }}</span></div>

						<template v-if="totals.card + totals.service > 0">
							<div class="ehtt-row" style="margin-top:14px;"><span class="ehtt-row-name ehtt-row-name-muted">Rozliczenie karty i serwisu po przelewie:</span><span></span></div>
							<div class="ehtt-row" v-if="totals.tips_tax > 0"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">— podatek</span><span>-{{ moneyFmt(totals.tips_tax) }}</span></div>
							<div class="ehtt-row" v-if="totals.tips_bar > 0"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">— dla baru</span><span>-{{ moneyFmt(totals.tips_bar) }}</span></div>
							<div class="ehtt-row" v-if="totals.tips_kitchen > 0"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">— dla kuchni</span><span>-{{ moneyFmt(totals.tips_kitchen) }}</span></div>
							<div class="ehtt-row"><span class="ehtt-row-name ehtt-row-name-muted" style="padding-left:14px;">= karta + serwis netto</span><span>{{ moneyFmt(totals.settlement_net) }}</span></div>
						</template>
						<div class="ehtt-total ehtt-strong" style="margin-top:14px;"><span>Łączny zarobek (netto)</span><span>{{ moneyFmt(totals.total_earnings) }}</span></div>
					</div>
				</template>
			</div>
		`,
		setup( props ) {
			const today = new Date();
			const year = ref( today.getFullYear() );
			const month = ref( today.getMonth() + 1 );
			const loading = ref( true );
			const cells = ref( [] );
			const totals = reactive( {
				hours_formatted: '0 h', hours_earnings: 0, card: 0, service: 0, cash: 0, cash_given: 0,
				cash_net: 0, bonus: 0, tips_gross: 0, tips_tax: 0, tips_kitchen: 0, tips_bar: 0,
				settlement_net: 0, tips_net: 0, total_earnings: 0, transfer_amount: 0, cash_amount: 0,
			} );
			const activeDays = computed( () => cells.value.filter( ( c ) => c.has_entry || c.tips.gross > 0 ) );
			const dow = [ 'Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Ndz' ];
			const todayIso = isoDate( today );

			const monthLabel = computed( () => {
				const names = [ 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień' ];
				return names[ month.value - 1 ] + ' ' + year.value;
			} );

			const leadingBlanks = computed( () => {
				const first = new Date( year.value, month.value - 1, 1 );
				const dayIdx = ( first.getDay() + 6 ) % 7; // tydzień zaczyna się w poniedziałek
				return Array.from( { length: dayIdx }, ( _, i ) => i );
			} );

			function cellClasses( cell ) {
				return {
					'ehtt-selected': cell.date === props.activeDate,
					'ehtt-has-entry': cell.has_entry && cell.date !== props.activeDate,
					'ehtt-today': cell.date === todayIso,
				};
			}

			async function load() {
				loading.value = true;
				try {
					const data = await api( '/month', { params: { year: year.value, month: month.value, user_id: props.userId } } );
					cells.value = data.days;
					Object.assign( totals, data.totals );
				} finally {
					loading.value = false;
				}
			}

			function prevMonth() {
				month.value--;
				if ( month.value < 1 ) { month.value = 12; year.value--; }
				load();
			}
			function nextMonth() {
				month.value++;
				if ( month.value > 12 ) { month.value = 1; year.value++; }
				load();
			}

			function moneyFmt( v ) {
				return money( v, props.currency );
			}

			watch( () => props.userId, load );
			onMounted( load );

			return { dow, leadingBlanks, cells, totals, monthLabel, prevMonth, nextMonth, loading, moneyFmt, todayIso, cellClasses, activeDays };
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Komponent: Ustawienia (tylko dla kierownika / administratora)     */
	/* ------------------------------------------------------------------ */
	const SettingsView = {
		props: [ 'employees' ],
		template: `
			<div class="ehtt-fade-in">
				<div class="ehtt-card">
					<h3>Stawki i podział napiwków</h3>
					<div class="ehtt-grid">
						<div>
							<label class="ehtt-field-label">Domyślna stawka godzinowa (ogólna, zł/h)</label>
							<input type="number" step="0.5" v-model="form.global_hourly_rate" />
						</div>
						<div>
							<label class="ehtt-field-label">Zaokrąglanie czasu (minuty)</label>
							<select v-model="form.round_minutes">
								<option :value="15">15 minut</option>
								<option :value="30">30 minut</option>
								<option :value="5">5 minut</option>
							</select>
						</div>
						<div>
							<label class="ehtt-field-label">Udział kuchni (%) — tylko dla napiwków wpisanych ręcznie</label>
							<input type="number" step="0.5" v-model="form.kitchen_deduction_pct" />
						</div>
						<div>
							<label class="ehtt-field-label">Udział baru (%) — tylko dla napiwków wpisanych ręcznie</label>
							<input type="number" step="0.5" v-model="form.bar_deduction_pct" />
						</div>
						<div>
							<label class="ehtt-field-label">Symbol waluty</label>
							<input type="text" v-model="form.currency" maxlength="5" />
						</div>
					</div>
					<p class="ehtt-muted">Połączenia: Grafik — <strong>{{ status.schedule ? 'aktywne' : 'brak wtyczki' }}</strong>, Napiwki — <strong>{{ status.tips ? 'aktywne' : 'brak wtyczki' }}</strong>. Napiwki z modułu Napiwków mają już odliczony podatek i udziały baru i kuchni według jego zasad.</p>
					<button class="ehtt-save" @click="saveSettings" :disabled="saving">
						<i class="fa-solid fa-check"></i> Zapisz ustawienia
					</button>
					<p v-if="msg" class="ehtt-msg">{{ msg }}</p>
				</div>

				<div class="ehtt-card">
					<h3>Indywidualna stawka pracownika</h3>
					<div class="ehtt-grid">
						<div>
							<label class="ehtt-field-label">Pracownik</label>
							<select v-model="selectedEmployee">
								<option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
							</select>
						</div>
						<div>
							<label class="ehtt-field-label">Stawka (zł/h)</label>
							<input type="number" step="0.5" v-model="employeeRate" />
						</div>
					</div>
					<button class="ehtt-save ehtt-secondary ehtt-inline" @click="saveEmployeeRate">Zapisz stawkę pracownika</button>
					<p class="ehtt-muted" style="margin-top:10px;">Ta stawka nadpisuje stawkę ogólną, ale nadpisanie na konkretny dzień (w widoku „Dzień”) ma zawsze priorytet wyższy.</p>
				</div>
			</div>
		`,
		setup( props ) {
			const form = reactive( {
				global_hourly_rate: 30, round_minutes: 15, kitchen_deduction_pct: 3,
				bar_deduction_pct: 3, currency: 'zł',
			} );
			const saving = ref( false );
			const msg = ref( '' );
			const selectedEmployee = ref( props.employees.length ? props.employees[ 0 ].id : null );
			const employeeRate = ref( '' );

			async function load() {
				const data = await api( '/settings' );
				Object.assign( form, data );
			}

			async function saveSettings() {
				saving.value = true;
				msg.value = '';
				try {
					await api( '/settings', { method: 'POST', body: form } );
					window.EHTT_SETTINGS = { ...form };
					msg.value = 'Zapisano ustawienia.';
				} finally {
					saving.value = false;
				}
			}

			async function saveEmployeeRate() {
				if ( ! selectedEmployee.value || ! employeeRate.value ) return;
				await api( '/user-rate', { method: 'POST', body: { user_id: selectedEmployee.value, rate: employeeRate.value } } );
				msg.value = 'Zapisano stawkę pracownika.';
			}

			onMounted( load );

			const status = window.EHTT_STATUS || { schedule: false, tips: false };
			return { form, saving, msg, saveSettings, selectedEmployee, employeeRate, saveEmployeeRate, status };
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Root App — bez własnego nagłówka (dostarcza go system nadrzędny);   */
	/*  pigułkowe taby + selektor pracownika w stylu wtyczki napiwków.      */
	/* ------------------------------------------------------------------ */
	const RootApp = {
		components: { DayView, CalendarView, SettingsView },
		template: `
			<div class="ehtt-fade-in">
				<div class="ehtt-tabs">
					<button @click="tab='day'" class="ehtt-tab" :class="{'ehtt-active': tab==='day'}">
						<i class="fa-solid fa-clock"></i> Dzień
					</button>
					<button @click="tab='calendar'" class="ehtt-tab" :class="{'ehtt-active': tab==='calendar'}">
						<i class="fa-solid fa-calendar-days"></i> Kalendarz i podsumowanie
					</button>
					<button v-if="canManage" @click="tab='settings'" class="ehtt-tab" :class="{'ehtt-active': tab==='settings'}">
						<i class="fa-solid fa-gear"></i> Ustawienia
					</button>
				</div>

				<div v-if="canManage && employees.length" class="ehtt-employee-picker">
					<label>Pracownik</label>
					<select v-model="selectedUserId">
						<option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
					</select>
				</div>

				<div class="ehtt-body">
					<div v-if="!ready" class="ehtt-loading">Wczytywanie aplikacji…</div>
					<template v-else>
						<day-view v-if="tab==='day'" :user-id="selectedUserId" :can-manage="canManage" :date="pickedDate"
							@saved="refreshFlag++" :key="'day-'+selectedUserId" />
						<calendar-view v-if="tab==='calendar'" :user-id="selectedUserId" :currency="currency" :active-date="pickedDate"
							@pick-day="onPickDay" :key="'cal-'+selectedUserId+'-'+refreshFlag" />
						<settings-view v-if="tab==='settings' && canManage" :employees="employees" />
					</template>
				</div>
			</div>
		`,
		setup() {
			const ready = ref( false );
			const canManage = ref( false );
			const employees = ref( [] );
			const selectedUserId = ref( CFG.userId );
			const tab = ref( 'day' );
			const pickedDate = ref( isoDate( new Date() ) );
			const currency = ref( 'zł' );
			const refreshFlag = ref( 0 );

			async function boot() {
				const me = await api( '/me' );
				canManage.value = me.can_manage;
				currency.value = me.settings.currency;
				window.EHTT_SETTINGS = me.settings;
				window.EHTT_STATUS = me.integrations;
				if ( canManage.value ) {
					employees.value = await api( '/employees' );
				}
				ready.value = true;
			}

			function onPickDay( date ) {
				pickedDate.value = date;
				tab.value = 'day';
			}

			onMounted( boot );

			return { ready, canManage, employees, selectedUserId, tab, pickedDate, currency, onPickDay, refreshFlag };
		},
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		const mountPoint = document.getElementById( 'ehtt-app' );
		if ( mountPoint ) {
			createApp( RootApp ).mount( mountPoint );
		}
	} );
})();
