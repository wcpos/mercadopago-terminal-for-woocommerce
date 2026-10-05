const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function makeNodeList(items) {
	const list = { length: items.length };
	items.forEach((item, index) => { list[index] = item; });
	return list;
}

class FakeElement {
	constructor(classes = []) {
		this.classNames = new Set(classes);
		this.attributes = {};
		this.children = [];
		this.listeners = {};
		this.style = {};
		this.value = '';
		this.textContent = '';
		this.className = classes.join(' ');
		this.disabled = false;
		this.hidden = false;
		this.scrollHeight = 0;
		this.scrollTop = 0;
		this.clickCount = 0;
	}
	append(child) { this.children.push(child); return child; }
	appendChild(child) { this.children.push(child); return child; }
	removeChild(child) { const i = this.children.indexOf(child); if (i >= 0) { this.children.splice(i, 1); } return child; }
	replaceChildren() { this.children = []; }
	get firstChild() { return this.children[0] || null; }
	// Mirror the real DOM: HTMLSelectElement.options is a read-only collection.
	get options() { return makeNodeList(this.children.filter((c) => 'option' === c.tagName)); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	removeAttribute(name) { delete this.attributes[name]; }
	getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
	addEventListener(type, callback) { (this.listeners[type] = this.listeners[type] || []).push(callback); }
	fire(type, event) { (this.listeners[type] || []).forEach((cb) => cb(event || { target: this, preventDefault() {} })); }
	// A disabled control emits no click, mirroring the real DOM.
	click() { if (this.disabled) { return; } this.clickCount++; this.fire('click', { target: this, preventDefault() {} }); }
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	querySelectorAll(selector) {
		const className = selector.replace(/^\./, '');
		const results = [];
		const walk = (node) => {
			node.children.forEach((child) => {
				if (child.classNames.has(className)) {
					results.push(child);
				}
				walk(child);
			});
		};
		walk(this);
		return makeNodeList(results);
	}
}

function makeButton(className, label) {
	const button = new FakeElement([className]);
	button.textContent = label;
	return button;
}

function makePanel(orderId, attrs = {}) {
	const root = new FakeElement(['mptfwc-payment-interface']);
	root.setAttribute('data-order-id', orderId);
	root.setAttribute('data-order-token', 'token-' + orderId);
	root.setAttribute('data-default-terminal-id', 'term_default');
	root.setAttribute('data-gateway-id', 'mercadopago_terminal_for_woocommerce');
	root.setAttribute('data-resume', '0');
	Object.keys(attrs).forEach((name) => root.setAttribute(name, attrs[name]));
	root.append(makeButton('mptfwc-toggle-log', 'Show logs')).setAttribute('data-expanded', 'false');
	root.append(makeButton('mptfwc-clear-log', 'Clear logs'));
	root.append(makeButton('mptfwc-copy-log', 'Copy logs'));
	const terminalField = root.append(new FakeElement(['mptfwc-terminal-field']));
	terminalField.append(new FakeElement(['mptfwc-terminal-select']));
	const primary = makeButton('mptfwc-primary-action', 'Start Terminal Payment');
	primary.setAttribute('data-mptfwc-mode', 'start');
	root.append(primary);
	root.append(new FakeElement(['mptfwc-payment-status']));
	const content = root.append(new FakeElement(['mptfwc-log-content']));
	content.style.display = 'none';
	root.append(new FakeElement(['mptfwc-payment-log-textarea']));
	return root;
}

async function flush() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve();
	}
}

(async () => {
	const panel = makePanel('123', { 'data-pos': '1' });
	const panels = [panel];
	const fetchCalls = [];
	const pendingFetches = [];
	const jqueryHandlers = {};
	const windowListeners = {};
	const beacons = [];
	const nativeBody = new FakeElement([]);
	const storage = {};
	const placeOrderButton = new FakeElement([]);
	let checkedPaymentMethod = 'mercadopago_terminal_for_woocommerce';

	// Controllable fake timers (payment.js uses setTimeout for the poll loop).
	let timerSeq = 0;
	const timers = {};
	const timerDelays = {};

	class FakeFormData {
		constructor() { this.fields = {}; }
		append(key, value) { this.fields[key] = String(value); }
	}

	const context = {
		console,
		Promise,
		Date,
		FormData: FakeFormData,
		setTimeout(fn, ms) { const id = ++timerSeq; timers[id] = fn; timerDelays[id] = ms; return id; },
		clearTimeout(id) { delete timers[id]; },
		fetch(url, options) {
			fetchCalls.push({ url, options });
			return new Promise((resolve) => { pendingFetches.push(resolve); });
		},
		document: {
			readyState: 'complete',
			body: nativeBody,
			addEventListener() {},
			createElement(tag) { const el = new FakeElement([]); el.tagName = tag; return el; },
			getElementById(id) { return 'place_order' === id ? placeOrderButton : null; },
			querySelector(selector) {
				if ('input[name="payment_method"]:checked' === selector) {
					return null === checkedPaymentMethod ? null : { value: checkedPaymentMethod };
				}
				return null;
			},
			querySelectorAll(selector) { return '.mptfwc-payment-interface' === selector ? panels.slice() : []; },
		},
		sessionStorage: {
			getItem(key) { return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null; },
			setItem(key, value) { storage[key] = String(value); },
			removeItem(key) { delete storage[key]; },
		},
		navigator: { clipboard: { writeText() { return Promise.resolve(); } } },
	};
	context.window = {
		mptfwcPaymentData: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			defaultTerminalId: 'term_default',
			pollIntervalMs: 2000,
			pollTimeoutMs: 300000,
			i18n: {
				logsHidden: 'Show logs', logsShown: 'Hide logs', copied: 'Copied', copyFailed: 'Copy failed',
				startAction: 'Start Terminal Payment', cancelAction: 'Cancel Terminal Payment',
				idle: 'Mercado Pago Terminal status: idle',
				atTerminal: 'Customer is paying on the terminal…',
				actionRequired: 'Confirm the payment on the terminal.',
				expired: 'The payment expired on the terminal. You can try again.',
				cancelOnTerminal: 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.',
				verificationFailed: 'Mercado Pago reported a payment that does not match this order. Check the order notes.',
			},
		},
		location: { href: 'https://example.test/wcpos-checkout/order-pay/123/' },
		addEventListener(type, callback) { (windowListeners[type] = windowListeners[type] || []).push(callback); },
		navigator: { sendBeacon(url, body) { beacons.push({ url, body }); return true; } },
		jQuery(target) { return { on(event, callback) { jqueryHandlers[event] = callback; return this; } }; },
	};
	context.jQuery = context.window.jQuery;
	function firePagehide() { (windowListeners.pagehide || []).forEach((callback) => callback()); }
	function firePaymentMethodChange() { nativeBody.fire('change', { target: { name: 'payment_method' } }); }

	function lastAction() {
		const call = fetchCalls[fetchCalls.length - 1];
		return call && call.options && call.options.body ? call.options.body.fields.action : null;
	}

	function resolveNext(data) {
		const resolve = pendingFetches.shift();
		assert(resolve, 'expected a pending fetch request');
		resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify({ success: true, data })) });
		return flush();
	}

	function resolveError(data) {
		const resolve = pendingFetches.shift();
		assert(resolve, 'expected a pending fetch request');
		resolve({ ok: false, status: 500, text: () => Promise.resolve(JSON.stringify({ success: false, data })) });
		return flush();
	}

	async function fireTimers() {
		const due = Object.keys(timers);
		due.forEach((id) => { const fn = timers[id]; delete timers[id]; fn(); });
		await flush();
	}

	vm.createContext(context);
	vm.runInContext(fs.readFileSync('assets/js/payment.js', 'utf8'), context);

	const action = panel.querySelector('.mptfwc-primary-action');
	const select = panel.querySelector('.mptfwc-terminal-select');
	const textarea = panel.querySelector('.mptfwc-payment-log-textarea');
	const statusEl = panel.querySelector('.mptfwc-payment-status');

	// On bind the panel fetches the terminal list to populate the dropdown.
	assert.strictEqual(fetchCalls.length, 1, 'binding a panel should request the terminal list');
	assert.strictEqual(lastAction(), 'mptfwc_list_terminals', 'first request should be mptfwc_list_terminals');
	// POS panels identify themselves so the server can build the POS thank-you URL.
	assert.strictEqual(fetchCalls[0].options.headers['X-WCPOS'], '1', 'POS panels should send the X-WCPOS header');
	await resolveNext({
		terminals: [
			{ id: 'term_A', label: 'Front desk', status: 'active' },
			{ id: 'term_default', label: 'Back office', status: 'active' },
		],
		default_terminal_id: 'term_default',
	});
	assert.strictEqual(select.value, 'term_default', 'the configured default terminal should be preselected');
	assert.strictEqual(select.options.length, 2, 'the dropdown should list the fetched terminals');
	assert.strictEqual(select.getAttribute('data-mptfwc-unavailable'), null, 'a populated dropdown must not carry the unavailable marker');
	// An idle panel shows a static "idle" status so the cashier sees the terminal is ready.
	assert(/idle/i.test(statusEl.textContent), 'an idle panel should show an idle status without polling');

	// The single primary button starts in "start" mode; duplicate clicks are deduped.
	assert.strictEqual(action.getAttribute('data-mptfwc-mode'), 'start', 'the primary button starts in start mode');
	action.click();
	action.click();
	assert.strictEqual(fetchCalls.length, 2, 'duplicate Start clicks should not queue a second request');
	assert.strictEqual(lastAction(), 'mptfwc_start_payment', 'Start should send mptfwc_start_payment');
	assert.strictEqual(action.disabled, true, 'the primary button should be disabled while starting');
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'start should send the selected terminal id');

	const accessToken = 'APP_USR-abcdefghijklmnopqrstuvwxyz123456';
	const testToken = 'TEST-abcdefghijklmnopqrstuvwxyz123456';
	await resolveNext({ status: 'created', mp_order_id: 'ORD123', message: accessToken + ' ' + testToken, metadata: { email: 'customer@example.com', access_token: accessToken } });
	assert(textarea.value.includes('ORD123'), 'browser logs should include the safe Mercado Pago order id');
	assert(textarea.value.includes('created'), 'browser logs should include the safe Mercado Pago payment status');
	assert(!textarea.value.includes('customer@example.com'), 'browser logs should not include customer metadata');
	assert(!textarea.value.includes(accessToken), 'browser logs should redact live access tokens even in messages');
	assert(!textarea.value.includes(testToken), 'browser logs should redact test access tokens even in messages');

	// After a successful start the flow enters auto-poll: the button flips to
	// Cancel and stays enabled; the terminal select is frozen; no immediate poll.
	assert.strictEqual(action.getAttribute('data-mptfwc-mode'), 'cancel', 'the button flips to cancel mode while waiting');
	assert.strictEqual(action.disabled, false, 'the cancel button is available while waiting for the terminal');
	assert.strictEqual(select.disabled, true, 'the terminal choice is frozen while a payment is in flight');
	assert.strictEqual(fetchCalls.length, 2, 'auto-poll should be scheduled, not fired immediately');

	assert.strictEqual(timerDelays[panel.mptfwcPoll.timer], 2000, 'poll scheduling should use pollIntervalMs');
	assert(panel.mptfwcPoll.deadline > Date.now() + 290000, 'the poll deadline should use pollTimeoutMs');

	// Mercado Pago pending states and server messages guide the cashier.
	await fireTimers();
	await resolveNext({ status: 'at_terminal' });
	assert.strictEqual(statusEl.textContent, context.window.mptfwcPaymentData.i18n.atTerminal, 'at_terminal should show atTerminal');
	await fireTimers();
	await resolveNext({ status: 'action_required' });
	assert.strictEqual(statusEl.textContent, context.window.mptfwcPaymentData.i18n.actionRequired, 'action_required should show actionRequired');
	await fireTimers();
	await resolveNext({ status: 'action_required', message: 'Follow the terminal instructions.' });
	assert.strictEqual(statusEl.textContent, 'Follow the terminal instructions.', 'a pending server message overrides the default text');
	await fireTimers();
	await resolveNext({ status: 'unknown' });
	assert(/waiting/i.test(statusEl.textContent), 'unknown should keep waiting');
	assert(!panel.mptfwcCompleted, 'pending statuses must not complete the order');

	// Drive many poll ticks (all pending) to prove the log stays bounded.
	for (let i = 0; i < 60; i++) {
		await fireTimers();
		assert.strictEqual(lastAction(), 'mptfwc_poll_payment', 'scheduled ticks should poll payment status');
		await resolveNext({ status: 'created' });
	}
	assert(textarea.value.split('\n').length <= 50, 'browser logs should keep a bounded number of lines');

	// Next scheduled poll returns paid with a redirect URL -> navigate straight
	// to the thank-you page (the order is already paid server-side).
	const thankYouUrl = 'https://example.test/wcpos-checkout/order-received/123?key=wc_order_test';
	await fireTimers();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, thankYouUrl, 'a paid poll should redirect to the thank-you page');
	assert.strictEqual(placeOrderButton.clickCount, 0, 'the order-pay form must not be re-submitted when a redirect URL is available');
	assert.strictEqual(panel.mptfwcCompleted, true, 'the panel should be marked complete after a paid poll');
	assert.strictEqual(action.getAttribute('data-mptfwc-mode'), 'start', 'the button returns to start mode after completion');

	// Resume: a panel rendered with data-resume="1" picks the poll loop back up
	// on load (no Start click needed) with the button already in cancel mode.
	const resumePanel = makePanel('321', { 'data-resume': '1' });
	panels.push(resumePanel);
	jqueryHandlers.updated_checkout();
	// bind() fetches the terminal list, then resumes polling.
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	assert(resumePanel.mptfwcPoll, 'a resuming panel should arm the poll loop on load');
	assert.strictEqual(resumePanel.querySelector('.mptfwc-primary-action').getAttribute('data-mptfwc-mode'), 'cancel', 'a resuming panel shows the cancel button');
	// Let the resumed poll settle as paid so it does not interfere with later panels.
	await fireTimers();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });

	// A failed cancel request must surface an error, not silently reset.
	const cancelPanel = makePanel('654');
	panels.push(cancelPanel);
	jqueryHandlers.updated_checkout();
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	const cancelAction = cancelPanel.querySelector('.mptfwc-primary-action');
	cancelAction.click();
	await resolveNext({ status: 'created' });
	assert.strictEqual(cancelAction.getAttribute('data-mptfwc-mode'), 'cancel', 'cancel panel is in cancel mode after start');
	cancelAction.click(); // now in cancel mode -> cancel request
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'clicking the button in cancel mode cancels the payment');
	await resolveError();
	assert(/failed/i.test(cancelPanel.querySelector('.mptfwc-payment-status').textContent), 'a failed cancel should show an error status');
	assert.strictEqual(cancelAction.disabled, false, 'button re-enables after a failed cancel');
	assert.strictEqual(cancelAction.getAttribute('data-mptfwc-mode'), 'start', 'button returns to start mode after a failed cancel');

	// A terminal-held payment stays cancellable and keeps polling after cancel.
	cancelAction.click();
	await resolveNext({ status: 'created' });
	cancelAction.click();
	await resolveNext({ status: 'cancel_on_terminal' });
	const cancelStatus = cancelPanel.querySelector('.mptfwc-payment-status');
	assert.strictEqual(cancelStatus.textContent, context.window.mptfwcPaymentData.i18n.cancelOnTerminal, 'terminal-held cancel shows cancelOnTerminal');
	assert(cancelStatus.className.includes('mptfwc-status-warning'), 'terminal-held cancel is a warning');
	assert.strictEqual(cancelAction.getAttribute('data-mptfwc-mode'), 'cancel', 'terminal-held payment keeps cancel mode');
	assert.strictEqual(cancelAction.disabled, false, 'the cashier can cancel again');
	assert(cancelPanel.mptfwcPoll && timers[cancelPanel.mptfwcPoll.timer], 'terminal-held cancel re-arms polling');
	assert.strictEqual(cancelPanel.querySelector('.mptfwc-terminal-select').disabled, true, 'terminal choice remains frozen');
	// A second click must issue another cancel request, not start a new payment.
	cancelAction.click();
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'a second cancel click sends another cancel');
	await resolveNext({ status: 'cancel_on_terminal', message: 'Cancel using the terminal.' });
	assert.strictEqual(cancelStatus.textContent, 'Cancel using the terminal.', 'terminal-held cancel shows the server message');
	await fireTimers();
	assert.strictEqual(lastAction(), 'mptfwc_poll_payment', 'terminal-held payment continues polling');
	const heldUrl = 'https://example.test/wcpos-checkout/order-received/654?key=wc_order_held';
	await resolveNext({ status: 'paid', redirect_url: heldUrl });
	assert.strictEqual(context.window.location.href, heldUrl, 'payment completed on the terminal redirects after cancel');
	assert.strictEqual(cancelPanel.mptfwcCompleted, true, 'terminal-held payment completes on paid');
	assert(!cancelPanel.mptfwcPoll, 'paid stops polling');

	// A cancel click may itself reconcile a payment that was just paid.
	cancelAction.click();
	await resolveNext({ status: 'created' });
	cancelAction.click();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, thankYouUrl, 'a clicked cancel answered paid redirects');
	assert.strictEqual(placeOrderButton.clickCount, 0, 'paid cancel with a redirect must not submit the form');

	// Switching payment method away from Mercado Pago Terminal stops and cancels an
	// in-flight payment so it does not linger open.
	const methodPanel = makePanel('987');
	panels.push(methodPanel);
	jqueryHandlers.updated_checkout();
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	methodPanel.querySelector('.mptfwc-primary-action').click();
	await resolveNext({ status: 'created' });
	assert(methodPanel.mptfwcPoll, 'method panel is polling after start');
	const fetchesBeforeSwitch = fetchCalls.length;
	checkedPaymentMethod = 'cod'; // customer decides to pay cash
	firePaymentMethodChange();
	await flush();
	assert(!methodPanel.mptfwcPoll, 'switching payment method stops the poll loop');
	assert.strictEqual(fetchCalls.length, fetchesBeforeSwitch + 1, 'switching payment method fires a cancel request');
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'switching payment method cancels the terminal payment');
	// The cancel is still in flight: nothing may claim the payment was canceled yet.
	assert(!/canceled/i.test(methodPanel.querySelector('.mptfwc-payment-status').textContent), 'the panel must wait for the cancel response before reporting a cancelation');
	await resolveNext({ status: 'canceled' });
	assert(/canceled/i.test(methodPanel.querySelector('.mptfwc-payment-status').textContent), 'a confirmed cancel reports the payment as canceled');

	// A method-switch cancel can also leave the payment held by the terminal.
	methodPanel.querySelector('.mptfwc-primary-action').click();
	await resolveNext({ status: 'created' });
	jqueryHandlers.payment_method_selected();
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'the jQuery method-switch event also cancels');
	await resolveNext({ status: 'cancel_on_terminal', message: 'Cancel on the device.' });
	assert.strictEqual(methodPanel.querySelector('.mptfwc-payment-status').textContent, 'Cancel on the device.', 'method-switch cancel displays the server warning');
	assert(methodPanel.querySelector('.mptfwc-payment-status').className.includes('mptfwc-status-warning'), 'method-switch terminal-held response is a warning');
	assert.strictEqual(methodPanel.querySelector('.mptfwc-primary-action').getAttribute('data-mptfwc-mode'), 'cancel', 'method-switch terminal-held response keeps cancel mode');
	assert(methodPanel.mptfwcPoll, 'method-switch terminal-held response re-arms polling');
	await fireTimers();
	await resolveNext({ status: 'canceled' });
	assert(/canceled/i.test(methodPanel.querySelector('.mptfwc-payment-status').textContent), 'a later terminal cancel is confirmed by polling');
	assert(!methodPanel.mptfwcPoll, 'a confirmed terminal cancel stops polling');

	// Race: the terminal approves at the very moment the cashier switches method.
	// The server reconciles the order as paid and answers the cancel with a
	// redirect URL — the panel must finish the order, not say "canceled".
	const raceUrl = 'https://example.test/wcpos-checkout/order-received/987?key=wc_order_race';
	const racePanel = makePanel('1010');
	panels.push(racePanel);
	jqueryHandlers.updated_checkout();
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	racePanel.querySelector('.mptfwc-primary-action').click();
	await resolveNext({ status: 'created' });
	checkedPaymentMethod = 'cod';
	firePaymentMethodChange();
	await flush();
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'the method switch fires the cancel');
	await resolveNext({ status: 'paid', redirect_url: raceUrl });
	assert.strictEqual(context.window.location.href, raceUrl, 'a cancel that comes back paid should complete the order');
	assert.strictEqual(racePanel.mptfwcCompleted, true, 'the panel is marked complete when the cancel reconciled as paid');
	assert(!/canceled/i.test(racePanel.querySelector('.mptfwc-payment-status').textContent), 'a paid order must never be reported as canceled');
	checkedPaymentMethod = 'mercadopago_terminal_for_woocommerce';

	// Checkout refresh binds new panels exactly once.
	const refreshedPanel = makePanel('456');
	panels.push(refreshedPanel);
	assert(jqueryHandlers.updated_checkout, 'payment.js should listen for WooCommerce checkout refreshes');
	jqueryHandlers.updated_checkout();
	jqueryHandlers.updated_checkout();
	refreshedPanel.querySelector('.mptfwc-toggle-log').click();
	assert.strictEqual(refreshedPanel.querySelector('.mptfwc-toggle-log').getAttribute('data-expanded'), 'true', 'checkout refresh binding should attach handlers once to new panels');
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });

	// Without a redirect URL, completion falls back to submitting #place_order.
	const refreshedAction = refreshedPanel.querySelector('.mptfwc-primary-action');
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	await fireTimers();
	await resolveNext({ status: 'paid' });
	assert.strictEqual(placeOrderButton.clickCount, 1, 'completion falls back to #place_order when no redirect URL is provided');

	// A timed-out auto-poll sends a cancel to Mercado Pago instead of leaving it open.
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	assert(refreshedPanel.mptfwcPoll, 'auto-poll should be armed after a successful start');
	refreshedPanel.mptfwcPoll.deadline = 0; // force the timeout branch on the next tick
	await fireTimers();
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'a timed-out auto-poll should auto-cancel the payment');
	assert(!/canceled/i.test(refreshedPanel.querySelector('.mptfwc-payment-status').textContent), 'timeout must not claim canceled while the request is pending');
	await resolveNext({ status: 'canceled' });
	assert(/canceled/i.test(refreshedPanel.querySelector('.mptfwc-payment-status').textContent), 'auto-cancel reports canceled only after confirmation');
	assert.strictEqual(refreshedAction.disabled, false, 'the button re-enables after a timed-out payment is canceled');
	assert.strictEqual(refreshedAction.getAttribute('data-mptfwc-mode'), 'start', 'the button returns to start mode after timeout');

	// While the timeout auto-cancel is in flight the button is frozen (a click
	// can't fire a stray request); once it resolves the cashier can retry.
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	refreshedPanel.mptfwcPoll.deadline = 0;
	await fireTimers(); // timeout branch fires the cancel
	assert.strictEqual(refreshedAction.disabled, true, 'the button is frozen while the timeout-cancel is in flight');
	refreshedAction.click(); // ignored: button disabled
	assert.strictEqual(lastAction(), 'mptfwc_cancel_payment', 'a click during the frozen window queues no new request');
	await resolveNext({ status: 'canceled' });
	assert.strictEqual(refreshedAction.disabled, false, 'the button re-enables after the timeout-cancel resolves');
	// Retry after the timeout resolved: a fresh Start proceeds to waiting.
	refreshedAction.click();
	assert.strictEqual(lastAction(), 'mptfwc_start_payment', 'a retry after timeout starts a fresh payment');
	assert(/sending/i.test(refreshedPanel.querySelector('.mptfwc-payment-status').textContent), 'the retry shows the sending state');
	await resolveNext({ status: 'created' });
	assert(/waiting/i.test(refreshedPanel.querySelector('.mptfwc-payment-status').textContent), 'the retry should proceed to the waiting state');
	// Wind the retry's poll loop down so it does not fire a pagehide beacon later.
	refreshedPanel.mptfwcPoll.deadline = 0;
	await fireTimers();
	await resolveNext({ status: 'canceled' });

	// A timeout cancel that is held at the terminal must resume polling too.
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	refreshedPanel.mptfwcPoll.deadline = 0;
	await fireTimers();
	await resolveNext({ status: 'cancel_on_terminal' });
	const refreshedStatus = refreshedPanel.querySelector('.mptfwc-payment-status');
	assert.strictEqual(refreshedStatus.textContent, context.window.mptfwcPaymentData.i18n.cancelOnTerminal, 'timeout terminal-held cancel shows the warning text');
	assert(refreshedStatus.className.includes('mptfwc-status-warning'), 'timeout terminal-held cancel is a warning');
	assert.strictEqual(refreshedAction.getAttribute('data-mptfwc-mode'), 'cancel', 'timeout terminal-held cancel keeps cancel mode');
	assert.strictEqual(refreshedAction.disabled, false, 'timeout terminal-held cancel re-enables the button');
	assert(refreshedPanel.mptfwcPoll && refreshedPanel.mptfwcPoll.deadline > Date.now(), 'timeout terminal-held cancel renews the poll deadline');
	await fireTimers();
	assert.strictEqual(lastAction(), 'mptfwc_poll_payment', 'after timeout terminal-held cancel the next tick polls');
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, thankYouUrl, 'a terminal-held timeout payment can still finish as paid');
	assert.strictEqual(refreshedPanel.mptfwcCompleted, true, 'paid after timeout marks the panel complete');

	// Each failed outcome stops polling and puts the primary button back to Start.
	for (const [paymentStatus, message] of [
		['expired', context.window.mptfwcPaymentData.i18n.expired],
		['verification_failed', context.window.mptfwcPaymentData.i18n.verificationFailed],
		['canceled', 'Payment canceled.'],
		['refunded', 'Payment failed. You can try again.'],
		['failed', 'Payment failed. You can try again.'],
	]) {
		refreshedAction.click();
		await resolveNext({ status: 'created' });
		await fireTimers();
		await resolveNext({ status: paymentStatus });
		assert.strictEqual(refreshedStatus.textContent, message, paymentStatus + ' shows its failure text');
		assert.strictEqual(refreshedAction.getAttribute('data-mptfwc-mode'), 'start', paymentStatus + ' returns to start mode');
		assert.strictEqual(refreshedAction.disabled, false, paymentStatus + ' enables a retry');
		assert(!refreshedPanel.mptfwcPoll, paymentStatus + ' stops polling');
		assert(!refreshedPanel.mptfwcCompleted, paymentStatus + ' must not complete the order');
	}

	// Idle remains idle; the other paid statuses also complete server-paid orders.
	refreshedAction.click();
	await resolveNext({ status: 'idle' });
	assert.strictEqual(refreshedStatus.textContent, context.window.mptfwcPaymentData.i18n.idle, 'idle response shows idle');
	assert.strictEqual(refreshedAction.getAttribute('data-mptfwc-mode'), 'start', 'idle response restores start mode');
	for (const paymentStatus of ['already_paid', 'conflict']) {
		refreshedAction.click();
		const redirect = thankYouUrl + '&status=' + paymentStatus;
		await resolveNext({ status: paymentStatus, redirect_url: redirect });
		assert.strictEqual(context.window.location.href, redirect, paymentStatus + ' redirects');
		assert.strictEqual(refreshedPanel.mptfwcCompleted, true, paymentStatus + ' completes the order');
	}

	// Locked panels never fetch the terminal list and always use the default terminal.
	const lockedPanel = makePanel('789', { 'data-lock-terminal': '1' });
	panels.push(lockedPanel);
	const fetchCountBefore = fetchCalls.length;
	jqueryHandlers.updated_checkout();
	assert.strictEqual(fetchCalls.length, fetchCountBefore, 'locked panels must not fetch the terminal list');
	lockedPanel.querySelector('.mptfwc-primary-action').click();
	assert.strictEqual(lastAction(), 'mptfwc_start_payment', 'locked panel Start should fire');
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'locked panels always send the default terminal');
	await resolveNext({ status: 'created' });

	// An empty terminal list disables the select and marks it unavailable.
	const emptyPanel = makePanel('999');
	panels.push(emptyPanel);
	jqueryHandlers.updated_checkout();
	const emptySelect = emptyPanel.querySelector('.mptfwc-terminal-select');
	await resolveNext({ terminals: [], default_terminal_id: '' });
	assert.strictEqual(emptySelect.disabled, true, 'an empty terminal list should disable the select');
	assert.strictEqual(emptySelect.getAttribute('data-mptfwc-unavailable'), '1', 'an empty terminal list should mark the select unavailable');

	// Closing the page while a payment is in flight fires one best-effort cancel
	// beacon (the locked panel is still polling).
	firePagehide();
	assert.strictEqual(beacons.length, 1, 'exactly one cancel beacon should fire for the in-flight payment');
	assert.strictEqual(beacons[0].body.fields.action, 'mptfwc_cancel_payment', 'the beacon should cancel the payment');
	assert.strictEqual(beacons[0].body.fields.order_id, '789', 'the beacon should target the in-flight order');

	console.log('payment-js ok');
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
