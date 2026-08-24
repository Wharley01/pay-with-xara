(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;
	var i18n = window.wp && window.wp.i18n;

	if (!registry || !settingsApi || !element || !htmlEntities || !i18n) {
		return;
	}

	var createElement = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var decodeEntities = htmlEntities.decodeEntities;
	var __ = i18n.__;
	var settings = settingsApi.getSetting('xara_data', {});
	var label = decodeEntities(settings.title || __('Pay with Xara', 'pay-with-xara'));

	var Label = function () {
		var children = [label];

		if (settings.icon) {
			children.unshift(
				createElement('img', {
					src: settings.icon,
					alt: label,
					style: {
						height: '24px',
						marginRight: '8px',
						verticalAlign: 'middle',
					},
				})
			);
		}

		return createElement('span', { className: 'xara-payment-method-label' }, children);
	};

	var Content = function (props) {
		var phoneState = useState('');
		var phone = phoneState[0];
		var setPhone = phoneState[1];
		var eventRegistration = props.eventRegistration || {};
		var emitResponse = props.emitResponse || {};
		var onPaymentSetup = eventRegistration.onPaymentSetup;
		var responseTypes = emitResponse.responseTypes || {};

		useEffect(
			function () {
				if (!onPaymentSetup) {
					return undefined;
				}

				return onPaymentSetup(function () {
					var value = (phone || '').trim();
					if (!value) {
						return {
							type: responseTypes.ERROR,
							message: __('Enter the WhatsApp number that should receive the Xara invoice.', 'pay-with-xara'),
						};
					}

					return {
						type: responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								xara_phone: value,
							},
						},
					};
				});
			},
			[phone, onPaymentSetup, responseTypes.ERROR, responseTypes.SUCCESS]
		);

		return createElement(
			'div',
			{ className: 'xara-payment-method' },
			createElement('div', {
				className: 'xara-payment-method-description',
				dangerouslySetInnerHTML: {
					__html: decodeEntities(settings.description || ''),
				},
			}),
			createElement(
				'label',
				{
					htmlFor: 'xara-phone',
					style: { display: 'block', marginTop: '12px', fontWeight: 600 },
				},
				__('WhatsApp phone number', 'pay-with-xara')
			),
			createElement('input', {
				id: 'xara-phone',
				type: 'tel',
				name: 'xara_phone',
				value: phone,
				required: true,
				placeholder: '0801 234 5678',
				autoComplete: 'tel',
				onChange: function (event) {
					setPhone(event.target.value);
				},
				style: {
					width: '100%',
					marginTop: '6px',
					padding: '8px 10px',
				},
			})
		);
	};

	registry.registerPaymentMethod({
		name: 'xara',
		label: createElement(Label),
		content: createElement(Content),
		edit: createElement(Content),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})();
